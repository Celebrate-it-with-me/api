<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Templates\TemplateService;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion as Version;
use App\Models\TemplatePackage;
use Illuminate\Support\Facades\DB;

/**
 * Activate, deactivate and withdraw a package (spec 6.3). Activating publishes the drafts the install left and turns
 * its templates on; events already published stay on the version they were fixed to (they never read `is_active`);
 * withdrawing is only possible when no event is fixed to the package.
 */
class PackageLifecycle
{
    public function __construct(
        private readonly TemplateService $templates,
        private readonly PackageQuery $query,
        private readonly PackageFiles $files,
        private readonly AdminAudit $audit,
        private readonly PackageThumbnail $thumbnails,
    ) {}

    /** @return array{package: array<string, mixed>, eventsOnPreviousVersion: int} */
    /** @param array<string, bool> $checklist the six confirmed items of the review (audited with who and when) */
    public function activate(int $id, string $reason, bool $ackThirdPartyMarks, array $checklist): array
    {
        $package = $this->query->find($id);

        if (! in_array($package->status, [TemplatePackage::INSTALLED, TemplatePackage::DEACTIVATED], true)) {
            throw PackageError::conflict('package_not_installable', 'Solo se activa un paquete instalado o desactivado.');
        }
        if ($package->engine_min > (int) config('invitation.engine')) {
            throw PackageError::invalid('package', 'engine_too_new', 'El motor actual es más viejo que el que pide el paquete.');
        }
        if (($package->manifest['thirdPartyMarks'] ?? []) !== [] && ! $ackThirdPartyMarks) {
            throw PackageError::invalid('ack_third_party_marks', 'required', 'Confirma que revisaste la autorización de uso de las marcas de terceros.');
        }

        $versions = Version::query()->where('package_id', $package->id)->orderBy('id')->get();
        // A version an upgrade already archived cannot come back: install the version again as a new semver instead.
        if ($versions->contains(fn (Version $v) => $v->status === Version::ARCHIVED)) {
            throw PackageError::conflict('package_not_installable', 'Una versión de este paquete ya fue archivada por una versión posterior: instala una versión nueva.');
        }

        $previousEvents = 0;
        DB::transaction(function () use ($package, $versions, $reason, &$previousEvents) {
            foreach ($versions as $version) {
                if ($version->isDraft()) {
                    $previousEvents += $this->templates->publish($version->id, $reason, viaPackage: true)['eventsOnPreviousVersion'];
                }
            }
            InvitationTemplate::query()->whereIn('id', $versions->pluck('template_id'))->update(['is_active' => true]);

            // Only the newest version of a slug is on; the older ones keep serving the events fixed to them.
            TemplatePackage::query()->where('slug', $package->slug)->where('id', '!=', $package->id)
                ->where('status', TemplatePackage::ACTIVE)
                ->update(['status' => TemplatePackage::DEACTIVATED, 'deactivated_at' => now()]);

            $package->forceFill(['status' => TemplatePackage::ACTIVE, 'activated_at' => now(), 'deactivated_at' => null])->save();
            // What the organizer sees in the selector is the package that is live.
            $this->thumbnails->link($package);
        });

        $this->audit->record('template.package_activated', ['template_package', $package->id], [
            'reason' => $reason,
            'after' => [
                'slug' => $package->slug,
                'version' => $package->semver,
                'eventsOnPreviousVersion' => $previousEvents,
                'ackThirdPartyMarks' => $ackThirdPartyMarks,
                'checklist' => $checklist,
                'confirmedAt' => now()->toIso8601String(),
            ],
        ]);

        return ['package' => $this->query->show($package->id), 'eventsOnPreviousVersion' => $previousEvents];
    }

    /** @return array<string, mixed> */
    public function deactivate(int $id, string $reason): array
    {
        $package = $this->query->find($id);

        if ($package->status !== TemplatePackage::ACTIVE) {
            throw PackageError::conflict('package_not_active', 'Solo se desactiva un paquete activo.');
        }

        $impact = $this->query->impact($id);
        DB::transaction(function () use ($package) {
            InvitationTemplate::query()->whereIn('id', Version::query()->where('package_id', $package->id)->pluck('template_id'))->update(['is_active' => false]);
            $package->forceFill(['status' => TemplatePackage::DEACTIVATED, 'deactivated_at' => now()])->save();
        });

        $this->audit->record('template.package_deactivated', ['template_package', $package->id], [
            'reason' => $reason,
            'after' => ['slug' => $package->slug, 'version' => $package->semver, 'eventsPinned' => $impact['eventsPinned'], 'eventsUnpublished' => $impact['eventsUnpublished']],
        ]);

        return $this->query->show($package->id);
    }

    public function withdraw(int $id, string $reason): void
    {
        $package = $this->query->find($id);

        if ($package->isWithdrawn()) {
            throw PackageError::conflict('package_withdrawn', 'El paquete ya está retirado.');
        }
        $pinned = $this->query->pinnedCounts([$id])[$id] ?? 0;
        if ($pinned > 0) {
            throw PackageError::conflict('package_in_use', 'Hay eventos fijados a este paquete: no se puede retirar.', ['eventsPinned' => $pinned]);
        }

        DB::transaction(function () use ($package) {
            $versions = Version::query()->where('package_id', $package->id)->get();
            InvitationTemplate::query()->whereIn('id', $versions->pluck('template_id'))->update(['is_active' => false]);
            foreach ($versions as $version) {
                // Rows stay as a record (only drafts can be deleted by the model's rule); they are archived.
                if ($version->status !== Version::ARCHIVED) {
                    $version->forceFill(['status' => Version::ARCHIVED, 'archived_at' => now()])->save();
                }
            }
            $package->forceFill(['status' => TemplatePackage::WITHDRAWN, 'withdrawn_at' => now()])->save();
        });

        if ($package->storage_path) {
            $this->files->delete($package->storage_path);
        }

        $this->audit->record('template.package_withdrawn', ['template_package', $package->id], [
            'reason' => $reason,
            'after' => ['slug' => $package->slug, 'version' => $package->semver],
        ]);
    }
}
