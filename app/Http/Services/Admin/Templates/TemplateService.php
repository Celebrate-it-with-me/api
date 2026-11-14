<?php

namespace App\Http\Services\Admin\Templates;

use App\Http\Services\Admin\AdminAudit;
use App\Models\Events;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion as Version;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The template manager (A7): templates with immutable versions, one draft at a time, one published. */
class TemplateService
{
    public function __construct(private readonly AdminAudit $audit) {}

    /** @return list<array<string, mixed>> */
    public function index(): array
    {
        $templates = InvitationTemplate::query()
            ->with(['currentVersion:id,template_id,version,published_at'])
            ->withMax(['versions as draft_version_id' => fn ($q) => $q->where('status', Version::DRAFT)], 'id')
            ->orderBy('id')
            ->get();

        $usage = $this->usage($templates);

        return $templates->map(fn (InvitationTemplate $t) => $this->templateRow($t, $usage))->all();
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        $template = InvitationTemplate::query()
            ->with(['currentVersion:id,template_id,version,published_at'])
            ->withMax(['versions as draft_version_id' => fn ($q) => $q->where('status', Version::DRAFT)], 'id')
            ->findOrFail($id);

        $usage = $this->usage(InvitationTemplate::query()->get(['id', 'event_type_id', 'tier', 'is_active']));
        $versions = $template->versions()->orderByDesc('version')
            ->get(['id', 'template_id', 'version', 'status', 'notes', 'created_at', 'published_at']);

        return $this->templateRow($template, $usage) + [
            'versions' => $versions->map(fn (Version $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'status' => $v->status,
                'notes' => $v->notes,
                'createdAt' => $v->created_at?->toIso8601String(),
                'publishedAt' => $v->published_at?->toIso8601String(),
                'eventsCount' => $this->versionEvents($v, $template, $usage),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function showVersion(int $id): array
    {
        $version = Version::query()->with(['creator:id,name', 'publisher:id,name', 'template'])->findOrFail($id);

        return $this->versionRow($version, $this->usage(InvitationTemplate::query()->get(['id', 'event_type_id', 'tier', 'is_active'])));
    }

    /** A draft copied from the published version (or the latest one). One draft per template. */
    public function createDraft(int $templateId): array
    {
        $version = DB::transaction(function () use ($templateId) {
            $template = InvitationTemplate::query()->lockForUpdate()->findOrFail($templateId);

            $draft = $template->versions()->where('status', Version::DRAFT)->first();
            if ($draft) {
                throw $this->conflict('draft_exists', 'Esta plantilla ya tiene un borrador.', ['draftVersionId' => $draft->id]);
            }

            $source = $template->currentVersion ?? $template->versions()->orderByDesc('version')->first();
            $next = (int) $template->versions()->max('version') + 1;

            return Version::query()->create([
                'template_id' => $template->id,
                'version' => $next,
                'status' => Version::DRAFT,
                'sections' => $source?->sections ?? $template->default_sections,
                'theme' => $source?->theme ?? $template->theme_defaults,
                'schema_version' => Version::SCHEMA_VERSION,
                'created_by' => request()->user()?->id,
            ]);
        });

        $this->audit->record('template.version_created', ['template_version', $version->id], [
            'after' => ['templateId' => $version->template_id, 'version' => $version->version],
        ]);

        return $this->showVersion($version->id);
    }

    /** @param  array{sections?: mixed, theme?: mixed, notes?: ?string}  $changes */
    public function updateDraft(int $id, array $changes): array
    {
        $version = DB::transaction(function () use ($id, $changes) {
            $version = Version::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($version);

            $sections = array_key_exists('sections', $changes) ? $changes['sections'] : $version->sections;
            $theme = array_key_exists('theme', $changes) ? $changes['theme'] : $version->theme;

            // The whole document must be valid after the change, so a draft can always be published.
            TemplateSchema::assertValid($sections, $theme);

            $version->sections = $sections;
            $version->theme = $theme;
            if (array_key_exists('notes', $changes)) {
                $version->notes = $changes['notes'];
            }
            $version->save();

            return $version;
        });

        $this->audit->record('template.version_updated', ['template_version', $version->id], [
            'after' => [
                'templateId' => $version->template_id,
                'version' => $version->version,
                'fields' => array_values(array_intersect(['sections', 'theme', 'notes'], array_keys($changes))),
            ],
        ]);

        return $this->showVersion($version->id);
    }

    /** @return array{version: array<string, mixed>, eventsOnPreviousVersion: int} */
    public function publish(int $id, string $reason): array
    {
        $previousEvents = 0;
        $previousId = null;

        $version = DB::transaction(function () use ($id, &$previousEvents, &$previousId) {
            $version = Version::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($version);
            TemplateSchema::assertValid($version->sections, $version->theme);

            $template = InvitationTemplate::query()->lockForUpdate()->findOrFail($version->template_id);
            $previous = $template->versions()->where('status', Version::PUBLISHED)->first();

            if ($previous) {
                $previousId = $previous->id;
                $previousEvents = Events::query()->where('template_version_id', $previous->id)->count();
                $previous->status = Version::ARCHIVED;
                $previous->archived_at = now();
                $previous->save();
            }

            $version->status = Version::PUBLISHED;
            $version->published_by = request()->user()?->id;
            $version->published_at = now();
            $version->save();

            $template->forceFill(['current_version_id' => $version->id])->save();

            return $version;
        });

        $this->audit->record('template.version_published', ['template_version', $version->id], [
            'reason' => $reason,
            'before' => ['previousVersionId' => $previousId],
            'after' => ['templateId' => $version->template_id, 'version' => $version->version, 'eventsOnPreviousVersion' => $previousEvents],
        ]);

        return ['version' => $this->showVersion($version->id), 'eventsOnPreviousVersion' => $previousEvents];
    }

    /** @return array<string, mixed> */
    public function archive(int $id, string $reason): array
    {
        $version = DB::transaction(function () use ($id) {
            $version = Version::query()->lockForUpdate()->findOrFail($id);

            if ($version->status === Version::PUBLISHED) {
                throw $this->unprocessable('cannot_archive_published', 'No se puede archivar la versión publicada: publica otra antes.');
            }
            $this->assertDraft($version);

            $version->status = Version::ARCHIVED;
            $version->archived_at = now();
            $version->save();

            return $version;
        });

        $this->audit->record('template.version_archived', ['template_version', $version->id], [
            'reason' => $reason,
            'after' => ['templateId' => $version->template_id, 'version' => $version->version],
        ]);

        return $this->showVersion($version->id);
    }

    private function assertDraft(Version $version): void
    {
        if (! $version->isDraft()) {
            throw $this->conflict('version_immutable', 'Solo se puede modificar un borrador: esta versión ya no lo es.');
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function conflict(string $code, string $message, array $extra = []): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code] + $extra, 409));
    }

    private function unprocessable(string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'code' => $code, 'errors' => ['version' => [$message]]], 422));
    }

    /**
     * Events per version (those fixed to it) and per template (the ones that follow its published version), in
     * two queries whatever the number of templates.
     *
     * @param  Collection<int, InvitationTemplate>  $templates
     * @return array{pinned: array<int, int>, following: array<int, int>, byTemplate: array<int, int>}
     */
    private function usage($templates): array
    {
        $rows = Events::query()
            ->groupBy('template_version_id', 'template_id', 'event_type_id')
            ->selectRaw('template_version_id, template_id, event_type_id, count(*) as total')
            ->get();

        $versionTemplate = Version::query()->pluck('template_id', 'id');
        $active = $templates->where('is_active', true);
        $free = $active->where('tier', InvitationTemplate::TIER_FREE)->sortBy('id');
        $fallback = fn (?int $typeId) => $free->firstWhere('event_type_id', $typeId)?->id
            ?? $free->firstWhere('event_type_id', null)?->id
            ?? $free->first()?->id;

        $pinned = [];
        $following = [];
        $byTemplate = [];

        foreach ($rows as $row) {
            $n = (int) $row->total;

            if ($row->template_version_id) {
                $pinned[$row->template_version_id] = ($pinned[$row->template_version_id] ?? 0) + $n;
                $tid = $versionTemplate[$row->template_version_id] ?? null;
            } else {
                $tid = $row->template_id && $active->contains('id', $row->template_id) ? $row->template_id : $fallback($row->event_type_id);
                if ($tid) {
                    $following[$tid] = ($following[$tid] ?? 0) + $n;
                }
            }

            if ($tid) {
                $byTemplate[$tid] = ($byTemplate[$tid] ?? 0) + $n;
            }
        }

        return ['pinned' => $pinned, 'following' => $following, 'byTemplate' => $byTemplate];
    }

    /** @param  array{pinned: array<int, int>, following: array<int, int>, byTemplate: array<int, int>}  $usage */
    private function versionEvents(Version $v, InvitationTemplate $template, array $usage): int
    {
        $own = $usage['pinned'][$v->id] ?? 0;

        return $v->id === $template->current_version_id ? $own + ($usage['following'][$template->id] ?? 0) : $own;
    }

    /** @param  array{pinned: array<int, int>, following: array<int, int>, byTemplate: array<int, int>}  $usage */
    private function templateRow(InvitationTemplate $t, array $usage): array
    {
        return [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->name,
            'eventTypeId' => $t->event_type_id,
            'tier' => $t->tier,
            'isActive' => $t->is_active,
            'currentVersion' => $t->currentVersion ? [
                'id' => $t->currentVersion->id,
                'version' => $t->currentVersion->version,
                'publishedAt' => $t->currentVersion->published_at?->toIso8601String(),
            ] : null,
            'draftVersionId' => $t->draft_version_id !== null ? (int) $t->draft_version_id : null,
            'eventsCount' => $usage['byTemplate'][$t->id] ?? 0,
        ];
    }

    /** @param  array{pinned: array<int, int>, following: array<int, int>, byTemplate: array<int, int>}  $usage */
    private function versionRow(Version $v, array $usage): array
    {
        return [
            'id' => $v->id,
            'templateId' => $v->template_id,
            'templateName' => $v->template?->name,
            'version' => $v->version,
            'status' => $v->status,
            'schemaVersion' => $v->schema_version,
            'sections' => $v->sections,
            'theme' => $v->theme,
            'notes' => $v->notes,
            'createdBy' => $v->creator ? ['id' => $v->creator->id, 'name' => $v->creator->name] : null,
            'publishedBy' => $v->publisher ? ['id' => $v->publisher->id, 'name' => $v->publisher->name] : null,
            'createdAt' => $v->created_at?->toIso8601String(),
            'publishedAt' => $v->published_at?->toIso8601String(),
            'archivedAt' => $v->archived_at?->toIso8601String(),
            'eventsCount' => $this->versionEvents($v, $v->template, $usage),
        ];
    }
}
