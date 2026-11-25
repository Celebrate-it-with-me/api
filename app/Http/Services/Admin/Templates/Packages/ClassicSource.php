<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\AppServices\ModuleSupport;
use App\Models\Events;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion as Version;
use App\Models\TemplatePackage;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\InvitationCache;
use Illuminate\Support\Facades\DB;

/**
 * Where the free Classic invitation comes from: the native components of the engine (`native`, the default) or the
 * reserved package `classic` (`package`). Docs: docs/plans/free-cover-composition-contract.md, 6.2 (C2).
 *
 * The switch is the pointer `current_version_id` of the two Classic templates, in the database (so every instance agrees,
 * and every change is audited). Installing the package never moves it. Versions are immutable and never touched: the
 * package versions are new rows, born `archived` (the unique "one published version per template" index belongs to the
 * native one; a package version is reachable only through the pointer, like any archived version is by the events fixed
 * to it). Events with a fixed `template_version_id` ignore the pointer, so they never change. Console only: there is no
 * HTTP route to install the reserved slug or to move the switch.
 */
class ClassicSource
{
    public const SLUG = 'classic';

    public const NATIVE = 'native';

    public const PACKAGE = 'package';

    public const TEMPLATES = ['classic-quinceanera', 'classic-wedding'];

    public function __construct(private readonly PackageInstaller $installer, private readonly AdminAudit $audit) {}

    /**
     * Installs (or re-checks) the package and parks its versions where the switch can point at them.
     *
     * @return array{status: 'installed'|'unchanged', package: TemplatePackage, missingModules: list<string>}
     */
    public function install(string $zipPath, string $reason, ?User $actor = null, ?string $expectedContentHash = null): array
    {
        self::assertReason($reason);
        // Parked and audited in the very transaction that creates the package: no half installed state is ever visible.
        $parkedNew = null;
        $result = $this->installer->installTrusted($zipPath, $reason, self::SLUG, function (TemplatePackage $package) use (&$parkedNew, $reason, $actor) {
            $parkedNew = $this->park($package);
            $this->audit->record('template.classic_package_installed', ['template_package', $package->id], [
                'reason' => $reason,
                'actor' => $actor,
                'after' => ['slug' => $package->slug, 'version' => $package->semver, 'contentHash' => $package->content_hash, 'versions' => $parkedNew, 'console' => self::console()],
            ]);
        }, $expectedContentHash);
        $package = $result['package'];

        // An older half installation (before this guarantee) is finished by running the command again.
        if ($result['status'] === 'unchanged') {
            DB::transaction(fn () => $this->park($package));
        }

        return ['status' => $result['status'], 'package' => $package->fresh(), 'missingModules' => ModuleSupport::missingFor($package)];
    }

    /** Parks the drafts of the package where the switch can point at them and marks the package active. @return int versions parked */
    private function park(TemplatePackage $package): int
    {
        $drafts = Version::query()->where('package_id', $package->id)->where('status', Version::DRAFT)->lockForUpdate()->get();
        foreach ($drafts as $draft) {
            $draft->forceFill(['status' => Version::ARCHIVED, 'archived_at' => now()])->save();
        }
        if ($package->status === TemplatePackage::INSTALLED) {
            $package->forceFill(['status' => TemplatePackage::ACTIVE, 'activated_at' => now()])->save();
        }

        return $drafts->count();
    }

    /** Who ran the console command, for the audit log (the console has no session): operating system user and host. @return array{osUser: string, host: string} */
    public static function console(): array
    {
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        return ['osUser' => (string) ($user ?? get_current_user() ?: 'console'), 'host' => (string) (gethostname() ?: 'unknown')];
    }

    /** The staff member named with --actor: must exist and be staff, checked before anything is written. */
    public static function actor(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        if ($user === null || ! $user->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            throw new \InvalidArgumentException('--actor debe ser el correo de una persona del equipo (staff).');
        }

        return $user;
    }

    /** The audit log keeps the reason in a 500 character column: checked before anything is written. */
    public static function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));
        if ($length < 5 || $length > 500) {
            throw new \InvalidArgumentException('La razón debe tener entre 5 y 500 caracteres.');
        }
    }

    /**
     * Published events of the two Classic templates with no fixed version (the Save the date stage fixes nothing): they
     * follow the pointer, so a change of source changes the invitation their guests already see. Drafts follow it too.
     */
    public function followingPublished(): int
    {
        $ids = InvitationTemplate::query()->whereIn('slug', self::TEMPLATES)->pluck('id');

        return Events::query()->whereIn('template_id', $ids)->whereNull('template_version_id')->where('status', 'published')->count();
    }

    /** @return 'native'|'package'|'mixed'|'unknown' what the two Classic templates point at now */
    public function current(): string
    {
        $templates = InvitationTemplate::query()->whereIn('slug', self::TEMPLATES)->with('currentVersion')->get();
        if ($templates->count() !== count(self::TEMPLATES)) {
            return 'unknown';
        }
        $sources = $templates->map(fn (InvitationTemplate $t) => $t->currentVersion?->package_id === null ? self::NATIVE : self::PACKAGE)->unique();

        return $sources->count() === 1 ? $sources->first() : 'mixed';
    }

    /**
     * Points both Classic templates at the native version or at the package version, all or nothing.
     *
     * @return array{changed: bool, from: string, to: string, following: int}
     */
    public function switchTo(string $mode, string $reason, ?User $actor = null): array
    {
        if (! in_array($mode, [self::NATIVE, self::PACKAGE], true)) {
            throw new \InvalidArgumentException('El origen debe ser native o package.');
        }
        self::assertReason($reason);

        $before = [];
        $after = [];
        DB::transaction(function () use ($mode, $reason, $actor, &$before, &$after) {
            $templates = InvitationTemplate::query()->whereIn('slug', self::TEMPLATES)->orderBy('id')->lockForUpdate()->get();
            if ($templates->count() !== count(self::TEMPLATES)) {
                throw new \RuntimeException('Faltan las plantillas Clásica (classic-quinceanera, classic-wedding).');
            }

            // Resolve both targets before writing anything: a missing one must leave the two templates as they were.
            $targets = [];
            foreach ($templates as $template) {
                $targets[$template->id] = $mode === self::PACKAGE ? $this->packageVersion($template) : $this->nativeVersion($template);
            }
            foreach ($templates as $template) {
                $before[$template->slug] = $template->current_version_id;
                $after[$template->slug] = $targets[$template->id]->id;
                if ($template->current_version_id !== $targets[$template->id]->id) {
                    $template->forceFill(['current_version_id' => $targets[$template->id]->id])->save();
                }
            }

            // The audit is part of the change: if it cannot be written, the pointers do not move.
            if ($before !== $after) {
                $this->audit->record('template.classic_source_changed', ['template', null], [
                    'reason' => $reason,
                    'actor' => $actor,
                    'before' => ['source' => $mode === self::PACKAGE ? self::NATIVE : self::PACKAGE, 'currentVersionIds' => $before],
                    'after' => ['source' => $mode, 'currentVersionIds' => $after, 'console' => self::console()],
                ]);
                InvitationCache::flushAll();
            }
        });

        $changed = $before !== $after;

        return ['changed' => $changed, 'from' => $mode === self::PACKAGE ? self::NATIVE : self::PACKAGE, 'to' => $mode, 'following' => $this->followingPublished()];
    }

    /** The newest version of the Classic package for this template that can be drawn (never a draft) and that the public may be shown. */
    private function packageVersion(InvitationTemplate $template): Version
    {
        $version = Version::query()
            ->where('template_id', $template->id)
            ->where('status', '!=', Version::DRAFT)
            ->whereHas('package', fn ($q) => $q->where('slug', self::SLUG)->where('status', '!=', TemplatePackage::WITHDRAWN))
            ->with('package')
            ->orderByDesc('id')
            ->first();

        if ($version === null) {
            throw new \RuntimeException("La plantilla {$template->slug} no tiene una versión del paquete Clásica instalada.");
        }
        $package = $version->package;
        $why = match (true) {
            $package->status !== TemplatePackage::ACTIVE => "el paquete está {$package->status}",
            $package->engine_min > (int) config('invitation.engine') => 'el paquete pide un motor más nuevo que el actual',
            $package->visibility !== TemplatePackage::PUBLIC => 'el paquete no es público',
            ($package->manifest['thirdPartyMarks'] ?? []) !== [] => 'el paquete declara marcas de terceros y su uso no se confirma desde este comando',
            default => null,
        };
        if ($why !== null) {
            throw new \RuntimeException("La Clásica no puede pasar a package: {$why}.");
        }
        if (ModuleSupport::missingFor($package) !== []) {
            throw new \RuntimeException('El paquete Clásica no trae todos los módulos (' . implode(', ', ModuleSupport::missingFor($package)) . '): las plantillas gratis dejarían de poder activarlos.');
        }

        return $version;
    }

    /** The native version: the one published row without a package. */
    private function nativeVersion(InvitationTemplate $template): Version
    {
        $version = Version::query()
            ->where('template_id', $template->id)
            ->whereNull('package_id')
            ->where('status', Version::PUBLISHED)
            ->first();

        if ($version === null) {
            throw new \RuntimeException("La plantilla {$template->slug} no tiene una versión nativa publicada a la que volver.");
        }

        return $version;
    }
}
