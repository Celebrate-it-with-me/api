<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Models\Events;
use App\Models\InvitationTemplateVersion as Version;
use App\Models\TemplatePackage;
use Illuminate\Pagination\LengthAwarePaginator;

/** Reads for the admin: list, detail and the impact of turning a package off. Shapes are the contract's (camelCase). */
class PackageQuery
{
    /**
     * @param  array{status?: ?string, visibility?: ?string, page?: ?int, per_page?: ?int}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);

        /** @var LengthAwarePaginator $rows */
        $rows = TemplatePackage::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['visibility'] ?? null, fn ($q, $v) => $q->where('visibility', $v))
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'slug', 'semver', 'level', 'visibility', 'tier', 'status', 'engine_min', 'manifest', 'size_bytes', 'installed_at', 'activated_at', 'deactivated_at', 'withdrawn_at'], 'page', $page);

        $pinned = $this->pinnedCounts($rows->pluck('id')->all());

        return [
            'data' => $rows->getCollection()->map(fn (TemplatePackage $p) => $this->summary($p, $pinned[$p->id] ?? 0))->all(),
            'meta' => ['page' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(), 'lastPage' => $rows->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        $package = $this->find($id);

        return $this->summary($package, $this->pinnedCounts([$id])[$id] ?? 0) + [
            'manifest' => $package->manifest,
            'description' => $package->manifest['description'] ?? null,
            'license' => $package->manifest['license'] ?? null,
            'credits' => $package->manifest['assetsCredits'] ?? [],
            'thirdPartyMarks' => $package->manifest['thirdPartyMarks'] ?? [],
            'variants' => $package->manifest['variants'] ?? (object) [],
            'editable' => $package->editable,
            'hasStrings' => $package->strings !== null,
            'files' => $package->files,
            'report' => $package->report,
            'contentHash' => $package->content_hash,
            'zipSha256' => $package->zip_sha256,
            'installedBy' => $package->installer ? ['id' => $package->installer->id, 'name' => $package->installer->name] : null,
            'templates' => $this->templates($package),
        ];
    }

    /** @return array{eventsPinned: int, eventsUnpublished: int, templates: list<array<string, mixed>>} */
    public function impact(int $id): array
    {
        $package = $this->find($id);
        $templates = $this->templates($package);
        $templateIds = array_column($templates, 'id');
        $unpublished = $templateIds === [] ? collect() : Events::query()
            ->whereIn('template_id', $templateIds)
            ->whereNull('template_version_id')
            ->where('status', '!=', 'published')
            ->groupBy('template_id')
            ->selectRaw('template_id, count(*) as total')
            ->pluck('total', 'template_id');
        $templates = array_map(fn (array $t) => $t + ['eventsUnpublished' => (int) ($unpublished[$t['id']] ?? 0)], $templates);

        return [
            'eventsPinned' => $this->pinnedCounts([$id])[$id] ?? 0,
            // Not published yet and following the template: they fall back to the free template when it goes off.
            'eventsUnpublished' => (int) $unpublished->sum(),
            'templates' => $templates,
        ];
    }

    public function find(int $id): TemplatePackage
    {
        $package = TemplatePackage::query()->with('installer:id,name')->find($id);
        if (! $package) {
            throw PackageError::notFound('package_not_found', 'El paquete no existe.');
        }

        return $package;
    }

    /** @return list<array{id: int, slug: string, name: string, versionId: int, versionStatus: string}> */
    public function templates(TemplatePackage $package): array
    {
        return Version::query()->where('package_id', $package->id)->with('template:id,slug,name')->orderBy('id')->get()
            ->map(fn (Version $v) => [
                'id' => $v->template->id,
                'slug' => $v->template->slug,
                'name' => $v->template->name,
                'versionId' => $v->id,
                'versionStatus' => $v->status,
            ])->all();
    }

    /**
     * Events fixed to a version of each package.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public function pinnedCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Events::query()
            ->join('invitation_template_versions as v', 'events.template_version_id', '=', 'v.id')
            ->whereIn('v.package_id', $ids)
            ->groupBy('v.package_id')
            ->selectRaw('v.package_id, count(*) as total')
            ->pluck('total', 'package_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, mixed> */
    public function summary(TemplatePackage $p, int $eventsPinned): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'semver' => $p->semver,
            'level' => $p->level,
            'visibility' => $p->visibility,
            'tier' => $p->tier,
            'status' => $p->status,
            'name' => $p->manifest['name'] ?? null,
            'eventTypes' => $p->manifest['eventTypes'] ?? [],
            'engineMin' => $p->engine_min,
            'author' => $p->manifest['author'] ?? null,
            'sizeBytes' => $p->size_bytes,
            'installedAt' => $p->installed_at?->toIso8601String(),
            'activatedAt' => $p->activated_at?->toIso8601String(),
            'deactivatedAt' => $p->deactivated_at?->toIso8601String(),
            'withdrawnAt' => $p->withdrawn_at?->toIso8601String(),
            'eventsPinned' => $eventsPinned,
        ];
    }
}
