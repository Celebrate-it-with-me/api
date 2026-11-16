<?php

namespace App\Http\Services\AppServices\Invitation;

use App\Enums\EventStatus;
use App\Http\Services\Admin\Templates\Packages\MissingPackagesUrl;
use App\Http\Services\Admin\Templates\Packages\PackageFiles;
use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\EventTemplateConfigValidator;
use App\Http\Services\AppServices\InvitationService;
use App\Http\Services\AppServices\PackagePayload;
use App\Http\Services\AppServices\TemplateCatalog;
use App\Http\Services\Logger\EventActivityLogger;
use App\Http\Services\Payments\PurchaseService;
use App\Models\Events;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion;
use App\Models\TemplatePackage;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the organizer does in "Tu invitación": read the editor and the catalog, change template, save fields and
 * sections, preview. The form is the `editable.json` of the package the event draws; the app sends values by field id
 * and this class is the only one that turns them into `events.template_config` (through EditableBinding), validates the
 * result with EventTemplateConfigValidator and enforces the plan (docs/plans/template-stage5-contract-draft.md).
 */
class InvitationEditor
{
    public const UNDO_MINUTES = 10;

    private const STASH_DAYS = 90;

    private const CONFIG_MAX_BYTES = 16384;

    /** Two edits of the same field within this many seconds are one entry of the activity feed. */
    private const LOG_BURST_SECONDS = 120;

    public function __construct(
        private readonly InvitationService $invitations,
        private readonly TemplateCatalog $catalog,
        private readonly EditableBinding $binding,
        private readonly EventTemplateConfigValidator $validator,
        private readonly PurchaseService $purchases,
        private readonly CoverPhoto $cover,
        private readonly EventModules $modules,
    ) {}

    // ---------------------------------------------------------------- reading

    /** @return array<string, mixed> `data` of `GET invitation` */
    public function state(Events $event, ?User $user = null): array
    {
        $ctx = $this->context($event);
        $unlocked = $this->purchases->isUnlocked($event);
        $pinned = $event->template_version_id !== null;
        $config = $event->template_config ?? [];

        return [
            'template' => $this->templateSummary($event, $ctx, $user),
            'state' => [
                'status' => $event->status,
                'stage' => $event->invitation_stage,
                'pinned' => $pinned,
                'templateLocked' => $this->isLocked($event),
                'unlocked' => $unlocked,
                'locale' => in_array($event->locale, InvitationService::LOCALES, true) ? $event->locale : 'es',
            ],
            'groups' => $this->groups($ctx),
            'fields' => array_map(fn (array $f) => $this->fieldPayload($f, $ctx, $config, $unlocked, $pinned, $event), $ctx['fields']),
            'sections' => $this->sectionsPayload($event, $ctx['content'], $config),
            'previewToken' => null,
            'savedAt' => $event->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> `data` of `GET invitation/templates` */
    public function templates(Events $event, ?User $user): array
    {
        $unlocked = $this->purchases->isUnlocked($event);
        $current = $this->invitations->templateFor($event)->id;

        return $this->catalog->availableFor($user, $event)
            ->map(function (InvitationTemplate $t) use ($unlocked, $current) {
                $package = $this->livePackage($t->currentVersion?->package);
                $manifest = (array) ($package?->manifest ?? []);

                return [
                    'id' => $t->id,
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'nameI18n' => $manifest['name'] ?? ['es' => $t->name, 'en' => $t->name],
                    'description' => $manifest['description'] ?? ['es' => null, 'en' => null],
                    'tier' => $t->tier,
                    'locked' => $t->tier === InvitationTemplate::TIER_PREMIUM && ! $unlocked,
                    'current' => $t->id === $current,
                    'previewImage' => $this->previewUrl($t->preview_image),
                    'eventTypes' => $manifest['eventTypes'] ?? array_values(array_filter([$t->eventType?->slug])),
                    'hasPresets' => $package !== null && count((array) ($package->tokens['presets'] ?? [])) > 1,
                    'presets' => $this->presets($package),
                ];
            })
            ->sortBy(fn (array $t) => [$t['current'] ? 0 : 1, $t['tier'] === InvitationTemplate::TIER_FREE ? 0 : 1, $t['id']])
            ->values()
            ->all();
    }

    /**
     * What changing to a template keeps and what it restarts (the confirmation dialog). Read only.
     *
     * @return array<string, mixed> `data` of `GET invitation/templates/{template}/impact`
     */
    public function impact(Events $event, ?User $user, string|int $templateId): array
    {
        $target = $this->availableTemplate($event, $user, $templateId);
        $ctx = $this->context($event);
        $config = $event->template_config ?? [];
        $unlocked = $this->purchases->isUnlocked($event);
        $targetFields = $this->invitations->editableFields($target->currentVersion?->package_id);
        $targetContent = $this->invitations->versionContent($this->asIf($event, $target), true);

        $keeps = [['kind' => 'sections']];
        if (! empty($config['sections']) && collect($config['sections'])->contains(fn ($s) => isset($s['title']) || isset($s['text']))) {
            $keeps[] = ['kind' => 'texts'];
        }
        $photo = $this->coverOverride($config);
        if ($photo !== null) {
            $keeps[] = ['kind' => 'cover_photo', 'editable' => collect($targetFields)->contains(fn ($f) => ($f['type'] ?? null) === 'image')];
        }

        $resets = [];
        $currentPreset = $config['theme']['preset'] ?? null;
        if ($currentPreset !== null) {
            $resets[] = ['kind' => 'preset', 'from' => $currentPreset, 'to' => $targetContent['theme']['preset'] ?? null];
        }
        if (! empty($config['theme']['colors']) || ! empty($config['theme']['fonts'])) {
            $resets[] = ['kind' => 'style', 'count' => count($config['theme']['colors'] ?? []) + count($config['theme']['fonts'] ?? [])];
        }
        if (! empty($config['package']['options'])) {
            $resets[] = ['kind' => 'options', 'count' => count($config['package']['options'])];
        }

        $stash = (array) ($event->template_config_stash ?? []);

        return [
            'templateId' => $target->id,
            'tier' => $target->tier,
            'locked' => $target->tier === InvitationTemplate::TIER_PREMIUM && ! $unlocked,
            'current' => $target->id === $ctx['template']->id,
            'willPin' => false,
            'keeps' => $keeps,
            'resets' => $resets,
            'restoresFromStash' => isset($stash[(string) $target->id]),
            'changesPublishedLook' => $event->status === EventStatus::PUBLISHED->value && $event->invitation_stage === Events::STAGE_SAVE_THE_DATE,
        ];
    }

    // ---------------------------------------------------------------- template

    /**
     * Applies a template to the event. Free while the invitation has not gone out; premium needs the full event.
     *
     * @return array<string, mixed> `data` of `GET invitation` plus `undo`
     */
    public function applyTemplate(Events $event, User $actor, string|int $templateId, bool $undo = false): array
    {
        $this->assertWritable($event);
        $target = InvitationTemplate::query()->with('currentVersion.package')->find($this->id($templateId));
        if ($target === null) {
            throw InvitationError::notFound('template_not_found', 'La plantilla no existe.');
        }
        if ($this->isLocked($event)) {
            throw InvitationError::conflict('template_locked', 'Tu invitación ya está publicada. Para cambiar de plantilla escríbenos y conservamos tus enlaces.');
        }
        $this->assertAvailable($event, $actor, $target);
        if ($target->tier === InvitationTemplate::TIER_PREMIUM && ! $this->purchases->isUnlocked($event)) {
            throw InvitationError::forbidden('plan_required', 'Esta plantilla es parte del evento completo.');
        }

        $fromId = $event->template_id ?? $this->invitations->templateFor($event)->id;
        $changed = false;

        DB::transaction(function () use ($event, $target, &$fromId, &$changed) {
            $row = Events::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($this->isLocked($row)) {
                throw InvitationError::conflict('template_locked', 'Tu invitación ya está publicada. Para cambiar de plantilla escríbenos y conservamos tus enlaces.');
            }
            $fromId = $row->template_id ?? $this->invitations->templateFor($row)->id;
            if ($fromId === $target->id && $row->template_id === $target->id) {
                return;
            }
            [$config, $stash] = $this->swap($row, $target, $fromId);
            $row->template_id = $target->id;
            $row->template_version_id = null;
            $row->template_config = $config === [] ? null : $config;
            $row->template_config_stash = $stash === [] ? null : $stash;
            $row->save();
            $changed = true;
        });

        $event->refresh()->unsetRelation('template');
        if ($changed) {
            EventActivityLogger::log($event->id, $undo ? 'invitation_template_undone' : 'invitation_template_changed', $actor, $event, ['from' => $fromId, 'to' => $target->id]);
        }

        return $this->state($event, $actor) + ['undo' => $changed ? ['until' => now()->addMinutes(self::UNDO_MINUTES)->toIso8601String(), 'templateId' => $fromId] : null];
    }

    /**
     * The config the event would have under another template: what is the event's (sections, texts, photo) stays;
     * the outgoing look is put aside by template and the incoming one comes back from where it was put aside.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the new config and stash
     */
    public function swap(Events $event, InvitationTemplate $target, int $fromId): array
    {
        $config = $event->template_config ?? [];
        $stash = $this->freshStash((array) ($event->template_config_stash ?? []));
        if ($fromId === $target->id) {
            return [$config, $stash];
        }

        $aspect = array_filter(['theme' => $config['theme'] ?? [], 'package' => $config['package'] ?? []]);
        if ($aspect !== []) {
            $stash[(string) $fromId] = $aspect + ['savedAt' => now()->toIso8601String()];
        }
        unset($config['theme'], $config['package']);

        $fields = $this->invitations->editableFields($target->currentVersion?->package_id);
        $unlocked = $this->purchases->isUnlocked($event);

        // Free colors follow the organizer when the new template has a color field with the same place and the plan allows it.
        foreach ((array) ($aspect['theme']['colors'] ?? []) as $key => $value) {
            $this->restoreLeaf($config, $fields, $event, $unlocked, ['theme' => ['colors' => [$key => $value]]]);
        }
        $back = $stash[(string) $target->id] ?? null;
        unset($stash[(string) $target->id]);
        if (is_array($back)) {
            foreach ((array) ($back['theme']['colors'] ?? []) as $key => $value) {
                $this->restoreLeaf($config, $fields, $event, $unlocked, ['theme' => ['colors' => [$key => $value]]]);
            }
            foreach ((array) ($back['theme']['fonts'] ?? []) as $role => $value) {
                $this->restoreLeaf($config, $fields, $event, $unlocked, ['theme' => ['fonts' => [$role => $value]]]);
            }
            if (isset($back['theme']['preset'])) {
                $this->restoreLeaf($config, $fields, $event, $unlocked, ['theme' => ['preset' => $back['theme']['preset']]]);
            }
            foreach ((array) ($back['package']['options'] ?? []) as $id => $value) {
                $this->restoreLeaf($config, $fields, $event, $unlocked, ['package' => ['options' => [$id => $value]]]);
            }
        }

        return [$config, $stash];
    }

    /** Puts one value back when the new template still accepts it (known field, valid value, allowed by the plan). */
    private function restoreLeaf(array &$config, array $fields, Events $event, bool $unlocked, array $fragment): void
    {
        try {
            $this->validator->validate($fragment, new TemplatePackage(['editable' => ['fields' => $fields]]), $unlocked, $event->id);
        } catch (ValidationException|HttpResponseException) {
            return;
        }
        $config = $this->binding->merge($config, $fragment);
    }

    // ---------------------------------------------------------------- fields

    /**
     * @param  array<string, mixed>  $input  field id => value (`null` goes back to the template's value)
     * @return array{fields: list<array<string, mixed>>, savedAt: ?string}
     */
    public function saveFields(Events $event, User $actor, mixed $input): array
    {
        $this->assertWritable($event);
        if (! is_array($input) || $input === []) {
            throw ValidationException::withMessages(['fields' => [$input === [] || $input === null ? 'required' : 'invalid_type']]);
        }
        if (array_is_list($input)) {
            throw ValidationException::withMessages(['fields' => ['invalid_type']]);
        }

        $ctx = $this->context($event);
        $unlocked = $this->purchases->isUnlocked($event);
        $package = new TemplatePackage(['editable' => ['fields' => $ctx['fields']]]);

        $fragments = [];
        $errors = [];
        foreach ($input as $id => $value) {
            $field = collect($ctx['fields'])->first(fn ($f) => ($f['id'] ?? null) === (string) $id);
            if ($field === null) {
                throw InvitationError::notFound('field_not_found', 'Ese campo no existe en la plantilla.');
            }
            [$fragment, $own] = $this->fieldFragment($field, $value, (string) $id);
            $errors += $own;
            if ($own !== []) {
                continue;
            }
            try {
                $this->validator->validate($this->binding->withoutNulls($fragment), $package, $unlocked, $event->id);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $path => $codes) {
                    $errors[$this->errorKey((string) $id, (string) $path, $field)] = [$codes[0]];
                }

                continue;
            }
            $fragments[(string) $id] = $fragment;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($event, $fragments, $ctx, $package) {
            $row = Events::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            // Merge into the stored config, not into what this request read: two tabs editing different fields both win.
            [$config, $dropped] = $this->binding->prune($row->template_config ?? [], $ctx['fields']);
            $stash = (array) ($row->template_config_stash ?? []);
            $own = $row->template_id ?? $ctx['template']->id;
            if ($dropped !== []) {
                // Not editable by this version any more (a newer one was activated): put aside, not lost.
                $stash[(string) $own] = $this->binding->merge((array) ($stash[(string) $own] ?? []), $dropped) + ['savedAt' => now()->toIso8601String()];
            }
            foreach ($fragments as $fragment) {
                $config = $this->binding->merge($config, $fragment);
            }
            // The whole result, not only what changed: the config is always something the package accepts.
            [$strict] = $this->binding->prune($config, $ctx['fields'], false);
            try {
                $this->validator->validate($strict, $package, true, $row->id);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['fields' => [array_values($e->errors())[0][0] ?? 'invalid_type']]);
            }
            if (strlen((string) json_encode($config)) > self::CONFIG_MAX_BYTES) {
                throw ValidationException::withMessages(['fields' => ['config_too_large']]);
            }
            $row->template_config = $config === [] ? null : $config;
            $row->template_config_stash = $stash === [] ? null : $stash;
            $row->save();
        });

        $event->refresh()->unsetRelation('template');
        foreach (array_keys($fragments) as $id) {
            // One entry per field and burst; only the id of the field, never its value (texts can be personal data).
            if (Cache::add("invitation.field-log.{$event->id}.{$id}", 1, self::LOG_BURST_SECONDS)) {
                EventActivityLogger::log($event->id, 'invitation_field_changed', $actor, $event, ['field' => $id]);
            }
        }

        $fresh = $this->context($event);
        $pinned = $event->template_version_id !== null;
        $config = $event->template_config ?? [];

        return [
            'fields' => collect($fresh['fields'])
                ->filter(fn ($f) => isset($fragments[$f['id']]))
                ->map(fn ($f) => $this->fieldPayload($f, $fresh, $config, $unlocked, $pinned, $event))
                ->values()->all(),
            'savedAt' => $event->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} the config fragment of one field, and the errors of its shape
     */
    private function fieldFragment(array $field, mixed $value, string $id): array
    {
        $type = $field['type'] ?? null;
        $key = "fields.{$id}";

        if ($type === 'image') {
            // The photo itself comes only through the upload endpoint: here only the point that must stay visible.
            if ($value === null) {
                return [$this->binding->focusFragment($field, null), []];
            }
            if (! is_array($value) || array_is_list($value)) {
                return [[], [$key => ['invalid_type']]];
            }
            if (array_diff(array_keys($value), ['focus']) !== []) {
                return [[], [$key => ['unknown_field']]];
            }

            return [$this->binding->focusFragment($field, $value['focus'] ?? null), []];
        }
        if ($type === 'text' && $value !== null) {
            if (! is_array($value) || array_is_list($value)) {
                return [[], [$key => ['invalid_type']]];
            }
            $unknown = array_diff(array_keys($value), InvitationService::LOCALES);
            if ($unknown !== []) {
                return [[], collect($unknown)->mapWithKeys(fn ($lang) => ["{$key}.{$lang}" => ['unknown_field']])->all()];
            }
        }

        return [$this->binding->fragment($field, $value), []];
    }

    private function errorKey(string $id, string $path, array $field): string
    {
        // sections.0.text.es -> fields.<id>.es ; anything else -> fields.<id>
        return preg_match('/^sections\.\d+\.(?:title|text)\.([^.]+)$/', $path, $m) === 1 ? "fields.{$id}.{$m[1]}" : "fields.{$id}";
    }

    // ---------------------------------------------------------------- sections

    /**
     * @param  mixed  $input  list of `{type, enabled, order}`
     * @return array{sections: list<array<string, mixed>>, savedAt: ?string}
     */
    public function saveSections(Events $event, User $actor, mixed $input): array
    {
        $this->assertWritable($event);
        if (! is_array($input) || $input === [] || ! array_is_list($input)) {
            throw ValidationException::withMessages(['sections' => [$input === [] || $input === null ? 'required' : 'invalid_type']]);
        }

        $ctx = $this->context($event);
        $errors = [];
        $changes = [];
        foreach ($input as $i => $item) {
            $at = "sections.{$i}";
            if (! is_array($item) || ! is_string($item['type'] ?? null) || ! in_array($item['type'], (array) config('invitation.section_types'), true) || isset($changes[$item['type']])) {
                $errors["{$at}.type"] = ['invalid_type'];

                continue;
            }
            if (array_diff(array_keys($item), ['type', 'enabled', 'order']) !== []) {
                $errors[$at] = ['invalid_type'];

                continue;
            }
            $type = $item['type'];
            $change = ['type' => $type, '_i' => $i];
            if (array_key_exists('enabled', $item)) {
                if (! is_bool($item['enabled'])) {
                    $errors["{$at}.enabled"] = ['invalid_type'];
                } elseif ($item['enabled'] === false && in_array($type, (array) config('invitation.required_sections'), true)) {
                    $errors["{$at}.enabled"] = ['required_section'];
                } elseif ($item['enabled'] === true && ($module = InvitationService::sectionModule($type)) !== null && ! $this->modules->isActive($event, $module)) {
                    $errors["{$at}.enabled"] = ['module_locked'];
                } else {
                    $change['enabled'] = $item['enabled'];
                }
            }
            if (array_key_exists('order', $item)) {
                if (! is_int($item['order']) || $item['order'] < 0 || $item['order'] > 99) {
                    $errors["{$at}.order"] = ['invalid_type'];
                } else {
                    $change['order'] = $item['order'];
                }
            }
            $changes[$type] = $change;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($event, $changes, $ctx) {
            $row = Events::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $config = $row->template_config ?? [];
            $fragment = ['sections' => array_values(array_map(fn (array $c) => array_diff_key($c, ['_i' => 1]), $changes))];
            $config = $this->binding->merge($config, $fragment);

            // The cover goes first: nothing may come before it.
            $effective = $this->effectiveSections($ctx['content'], $config);
            if (collect($changes)->contains(fn (array $c) => isset($c['order']))) {
                $cover = $effective['cover']['order'] ?? 0;
                $errors = [];
                foreach ($effective as $type => $section) {
                    if ($type !== 'cover' && ($section['order'] ?? 99) <= $cover) {
                        $at = $changes[$type]['_i'] ?? $changes['cover']['_i'] ?? 0;
                        $errors["sections.{$at}.order"] = ['cover_first'];
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            $row->template_config = $config === [] ? null : $config;
            $row->save();
        });

        $event->refresh()->unsetRelation('template');

        return [
            'sections' => $this->sectionsPayload($event, $ctx['content'], $event->template_config ?? []),
            'savedAt' => $event->updated_at?->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- cover photo

    /**
     * @return array{url: string, thumbUrl: string, width: int, height: int, focus: ?string, bytes: int}
     */
    public function storeCover(Events $event, User $actor, ?UploadedFile $file, ?string $focus): array
    {
        $this->assertWritable($event);
        $ctx = $this->context($event);
        $field = $this->coverField($ctx['fields']);
        if ($field === null) {
            throw InvitationError::conflict('package_no_cover_field', 'Esta plantilla no tiene foto de portada.');
        }
        if (($field['plan'] ?? 'free') === 'full' && ! $this->purchases->isUnlocked($event)) {
            throw InvitationError::forbidden('plan_required', 'Esta personalización es parte del evento completo.');
        }
        if ($focus !== null && preg_match('/^\d{1,3}% \d{1,3}%$/', $focus) !== 1) {
            throw ValidationException::withMessages(['focus' => ['invalid_focus']]);
        }

        $stored = $this->cover->store($event->id, $file, (int) ($field['minWidth'] ?? 0));
        $previous = null;
        try {
            DB::transaction(function () use ($event, $field, $stored, $focus, &$previous) {
                $row = Events::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $previous = $this->coverOverride($row->template_config ?? [], $field);
                $fragment = $this->binding->merge([], $this->binding->fragment($field, CoverPhoto::PREFIX . $stored['path']));
                $config = $this->binding->merge($row->template_config ?? [], $fragment);
                $config = $this->binding->merge($config, $this->binding->focusFragment($field, $focus));
                $row->template_config = $config;
                $row->save();
            });
        } catch (\Throwable $e) {
            $this->cover->delete($stored['path']);
            throw $e;
        }

        // Only now, with the new photo saved, the old one goes: a failure leaves no event without its photo.
        if (is_string($previous) && ($old = CoverPhoto::pathFor($previous, $event->id)) !== null) {
            $this->cover->delete($old);
        }
        $event->refresh()->unsetRelation('template');
        EventActivityLogger::log($event->id, 'invitation_cover_uploaded', $actor, $event, []);

        return $this->cover->urls($stored['path']) + [
            'width' => $stored['width'],
            'height' => $stored['height'],
            'focus' => $focus,
            'bytes' => $stored['bytes'],
        ];
    }

    /** Removes the cover photo (files and override). Nothing to remove is not an error. */
    public function removeCover(Events $event, User $actor): void
    {
        $this->assertWritable($event);
        $ctx = $this->context($event);
        $field = $this->coverField($ctx['fields']);
        $previous = null;

        DB::transaction(function () use ($event, $field, &$previous) {
            $row = Events::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $config = $row->template_config ?? [];
            // Whatever template the config was written under: a photo can outlive a template that has no field for it.
            $previous = $this->coverOverride($config, $field);
            $target = $field ?? ['bind' => ['section' => 'cover', 'setting' => 'image', 'focus' => 'focus']];
            $config = $this->binding->merge($config, $this->binding->fragment($target, null));
            $config = $this->binding->merge($config, $this->binding->focusFragment($target, null));
            $row->template_config = $config === [] ? null : $config;
            $row->save();
        });

        if (is_string($previous) && ($old = CoverPhoto::pathFor($previous, $event->id)) !== null) {
            $this->cover->delete($old);
            EventActivityLogger::log($event->id, 'invitation_cover_removed', $actor, $event, []);
        }
        $event->refresh()->unsetRelation('template');
    }

    /** @param list<array<string, mixed>> $fields */
    private function coverField(array $fields): ?array
    {
        return collect($fields)->first(fn ($f) => ($f['type'] ?? null) === 'image' && isset($f['bind']['section'], $f['bind']['setting'])
            && $f['bind']['section'] === 'cover');
    }

    /** The stored value of the cover photo, whatever it is. @param array<string, mixed> $config */
    private function coverOverride(array $config, ?array $field = null): ?string
    {
        $setting = $field['bind']['setting'] ?? 'image';
        foreach ((array) ($config['sections'] ?? []) as $section) {
            if (($section['type'] ?? null) === 'cover' && is_string($section['settings'][$setting] ?? null)) {
                return $section['settings'][$setting];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- preview

    /**
     * The invitation as a guest would get it, with the event's real data and the changes the organizer has not saved,
     * applied in memory. Same shape as `GET invitation/{slug}/{code}`. Writes nothing.
     *
     * @param  array<string, mixed>  $body  `template_id`, `fields`, `locale`, `state`, `preset`
     * @return array<string, mixed>
     */
    public function preview(Events $event, ?User $user, array $body): array
    {
        $copy = clone $event;
        $copy->unsetRelation('template');
        $config = $event->template_config ?? [];

        if (isset($body['template_id'])) {
            $target = $this->availableTemplate($event, $user, $body['template_id']);
            $copy->template_id = $target->id;
            $copy->template_version_id = null;
            $copy->setRelation('template', $target);
            [$config] = $this->swap($copy, $target, $event->template_id ?? $this->invitations->templateFor($event, true)->id);
        }
        $copy->template_config = $config;
        $ctx = $this->context($copy, true);
        $package = new TemplatePackage(['editable' => ['fields' => $ctx['fields']]]);

        $errors = [];
        $apply = function (array $fragment) use (&$config, $package, $copy) {
            // Looking is free: the plan is not checked here, only that the value is valid.
            $this->validator->validate($this->binding->withoutNulls($fragment), $package, true, $copy->id);
            $config = $this->binding->merge($config, $fragment);
        };
        foreach ((array) ($body['fields'] ?? []) as $id => $value) {
            $field = collect($ctx['fields'])->first(fn ($f) => ($f['id'] ?? null) === (string) $id);
            if ($field === null) {
                throw InvitationError::notFound('field_not_found', 'Ese campo no existe en la plantilla.');
            }
            [$fragment, $own] = $this->fieldFragment($field, $value, (string) $id);
            if ($own !== []) {
                $errors += $own;

                continue;
            }
            try {
                $apply($fragment);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $path => $codes) {
                    $errors[$this->errorKey((string) $id, (string) $path, $field)] = [$codes[0]];
                }
            }
        }
        if (isset($body['preset'])) {
            $field = collect($ctx['fields'])->first(fn ($f) => ($f['bind']['theme'] ?? null) === 'preset');
            try {
                if ($field === null) {
                    throw ValidationException::withMessages(['preset' => ['invalid_choice']]);
                }
                $apply($this->binding->fragment($field, $body['preset']));
            } catch (ValidationException) {
                $errors['preset'] = ['invalid_choice'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $state = $body['state'] ?? 'invitation';
        if ($state === 'no_photo') {
            $config = $this->binding->merge($config, $this->binding->fragment(['bind' => ['section' => 'cover', 'setting' => 'image']], null));
        }
        $copy->template_config = $config;
        $copy->invitation_stage = $state === 'save_the_date' ? Events::STAGE_SAVE_THE_DATE : Events::STAGE_INVITATION;

        $guest = new Guest(['name' => $body['guest_name'] ?? ($event->locale === 'en' ? 'Guest' : 'Invitada/o')]);

        return $this->invitations->build($copy, $guest, $body['locale'] ?? null, true);
    }

    // ---------------------------------------------------------------- shared

    /** The event is fixed to the version it was published with, from the full invitation on (D-1, D-3). */
    public function isLocked(Events $event): bool
    {
        return $event->template_version_id !== null && $event->invitation_stage !== Events::STAGE_SAVE_THE_DATE;
    }

    private function assertWritable(Events $event): void
    {
        if ($event->status === EventStatus::ARCHIVED->value) {
            throw ValidationException::withMessages(['status' => ['event_archived']]);
        }
    }

    private function id(string|int $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function availableTemplate(Events $event, ?User $user, string|int $templateId): InvitationTemplate
    {
        $target = InvitationTemplate::query()->with('currentVersion.package')->find($this->id($templateId));
        if ($target === null) {
            throw InvitationError::notFound('template_not_found', 'La plantilla no existe.');
        }
        $this->assertAvailable($event, $user, $target);

        return $target;
    }

    private function assertAvailable(Events $event, ?User $user, InvitationTemplate $target): void
    {
        if (! $this->catalog->availableFor($user, $event)->contains('id', $target->id)) {
            throw InvitationError::forbidden('template_not_available', 'Esta plantilla no está disponible para tu evento.');
        }
    }

    /** The event as if it already used a template (nothing is saved). */
    private function asIf(Events $event, InvitationTemplate $template): Events
    {
        $copy = clone $event;
        $copy->template_id = $template->id;
        $copy->template_version_id = null;
        $copy->setRelation('template', $template);

        return $copy;
    }

    /** @param array<string, mixed> $stash @return array<string, mixed> */
    private function freshStash(array $stash): array
    {
        return array_filter($stash, fn ($entry) => is_array($entry)
            && (! isset($entry['savedAt']) || now()->diffInDays($entry['savedAt'], true) <= self::STASH_DAYS));
    }

    /** @return list<array{value: string, label: array<string, mixed>, colors?: list<string>}> the palettes the organizer can pick */
    private function presets(?TemplatePackage $package): array
    {
        if ($package === null) {
            return [];
        }
        $tokens = (array) ($package->tokens['presets'] ?? []);
        $field = collect((array) ($package->editable['fields'] ?? []))->first(fn ($f) => ($f['bind']['theme'] ?? null) === 'preset');
        if ($field === null) {
            return [];
        }

        return collect((array) ($field['choices'] ?? []))->map(function ($choice) use ($tokens) {
            $colors = (array) ($tokens[$choice['value']]['colors'] ?? []);
            $swatch = array_values(array_filter([$colors['surface'] ?? null, $colors['accent'] ?? null, $colors['primary'] ?? null]));

            return ['value' => $choice['value'], 'label' => $choice['label'] ?? ['es' => $choice['value'], 'en' => $choice['value']]] + ($swatch === [] ? [] : ['colors' => $swatch]);
        })->values()->all();
    }

    private function livePackage(?TemplatePackage $package): ?TemplatePackage
    {
        return $package === null || $package->isWithdrawn() ? null : $package;
    }

    private function previewUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }
        try {
            return app(PackageFiles::class)->url($path);
        } catch (MissingPackagesUrl) {
            return null;
        }
    }

    /**
     * @return array{template: InvitationTemplate, versionId: ?int, version: ?InvitationTemplateVersion, content: array<string, mixed>, package: ?TemplatePackage, fields: list<array<string, mixed>>, editable: array<string, mixed>}
     */
    private function context(Events $event, bool $ignorePlan = false): array
    {
        $template = $this->invitations->templateFor($event, $ignorePlan);
        $content = $this->invitations->versionContent($event, $ignorePlan);
        $versionId = $this->invitations->currentVersionId($event, $ignorePlan);
        $package = isset($content['package_id']) ? $this->livePackage(TemplatePackage::query()->find($content['package_id'])) : null;
        $editable = (array) ($package?->editable ?? []);

        return [
            'template' => $template,
            'versionId' => $versionId,
            'version' => $versionId ? InvitationTemplateVersion::query()->find($versionId) : null,
            'content' => $content,
            'package' => $package,
            'fields' => array_values(array_filter((array) ($editable['fields'] ?? []), 'is_array')),
            'editable' => $editable,
        ];
    }

    /** @return array<string, mixed> */
    private function templateSummary(Events $event, array $ctx, ?User $user): array
    {
        $template = $ctx['template'];
        $package = $ctx['package'];
        $available = $template->is_active && $this->catalog->allows($template, $event, $user);

        return [
            'id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'tier' => $template->tier,
            'level' => (int) ($package?->level ?? 0),
            'packageSlug' => $package?->slug,
            'versionId' => $ctx['versionId'],
            'versionSemver' => $package?->semver ?? ($ctx['version'] ? (string) $ctx['version']->version : null),
            'available' => $available,
            'fallbackToClassic' => $event->template_id !== null && $event->template_id !== $template->id,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function groups(array $ctx): array
    {
        $used = array_unique(array_filter(array_column($ctx['fields'], 'group')));

        return array_values(array_filter(
            array_filter((array) ($ctx['editable']['groups'] ?? []), 'is_array'),
            fn ($g) => in_array($g['id'] ?? null, $used, true)
        ));
    }

    /** @return array<string, mixed> */
    private function fieldPayload(array $field, array $ctx, array $config, bool $unlocked, bool $pinned, Events $event): array
    {
        $locked = ($field['plan'] ?? 'free') === 'full' && ! $unlocked;
        $type = $field['type'] ?? null;
        $base = array_filter([
            'id' => $field['id'],
            'type' => $type,
            'group' => $field['group'] ?? null,
            'label' => $field['label'] ?? null,
            'help' => $field['help'] ?? null,
            'plan' => $field['plan'] ?? 'free',
            'locked' => $locked,
            'required' => (bool) ($field['required'] ?? false),
            'aspect' => $field['aspect'] ?? null,
            'minWidth' => $field['minWidth'] ?? null,
            'multiline' => $type === 'text' ? (bool) ($field['multiline'] ?? false) : null,
            'max' => $field['max'] ?? null,
            'choices' => $field['choices'] ?? null,
        ], fn ($v) => $v !== null);

        [$value, $default, $isDefault] = $this->effective($field, $ctx, $config, $event);
        // A locked field shows the template's own value, unless the event is fixed: it keeps what it paid for.
        if ($locked && ! $pinned) {
            $value = $default;
            $isDefault = true;
        }

        return $base + ['value' => $value, 'default' => $default, 'isDefault' => $isDefault];
    }

    /** @return array{0: mixed, 1: mixed, 2: bool} value, default, isDefault */
    private function effective(array $field, array $ctx, array $config, Events $event): array
    {
        $bind = (array) ($field['bind'] ?? []);
        $theme = (array) ($ctx['content']['theme'] ?? []);
        $override = (array) ($config['theme'] ?? []);

        if (($field['type'] ?? null) === 'image') {
            $stored = $this->coverOverride($config, $field);
            $setting = $bind['setting'] ?? 'image';
            $focusKey = $bind['focus'] ?? 'focus';
            $focus = null;
            foreach ((array) ($config['sections'] ?? []) as $section) {
                if (($section['type'] ?? null) === ($bind['section'] ?? null)) {
                    $focus = $section['settings'][$focusKey] ?? null;
                }
            }
            if ($stored === null) {
                return [null, null, true];
            }
            $url = $this->invitations->coverImage($stored, $event);
            $path = CoverPhoto::pathFor($stored, $event->id);
            $size = $path ? CoverPhoto::dimensions($path) : null;

            return [[
                'url' => $url,
                'thumbUrl' => $path ? $this->cover->urls($path)['thumbUrl'] : $url,
                'focus' => is_string($focus) ? $focus : null,
                'width' => $size['width'] ?? null,
                'height' => $size['height'] ?? null,
            ], null, false];
        }

        if (isset($bind['section'], $bind['field'])) {
            $base = collect($ctx['content']['sections'])->firstWhere('type', $bind['section']);
            $default = $this->bothLocales($base[$bind['field']] ?? null);
            $own = collect($config['sections'] ?? [])->firstWhere('type', $bind['section']);
            $set = $this->bothLocales($own[$bind['field']] ?? null);
            $value = ['es' => $set['es'] ?? $default['es'], 'en' => $set['en'] ?? $default['en']];

            return [$value, $default, array_filter($set, fn ($v) => $v !== null) === []];
        }

        if (isset($bind['theme'])) {
            $path = explode('.', (string) $bind['theme']);
            $defaults = $this->invitations->themePayload($theme, []);
            $current = $this->invitations->themePayload($theme, $override);
            if ($path[0] === 'preset') {
                return [$current['preset'], $defaults['preset'], ! isset($override['preset'])];
            }
            $group = $path[0];
            $key = $path[1] ?? '';
            // The default of a color is the one of the preset in use, so "reset" lands on what the preset gives.
            $presetOnly = $this->invitations->themePayload($theme, array_intersect_key($override, ['preset' => 1]));
            $default = $presetOnly[$group][$key] ?? ($field['default'] ?? null);

            return [$current[$group][$key] ?? $default, $default, ! isset($override[$group][$key])];
        }

        if (isset($bind['option'])) {
            $id = (string) $bind['option'];
            $defaults = $ctx['package'] ? (app(PackagePayload::class)->fragment($ctx['package']->id)['options']['defaults'] ?? []) : [];
            $default = $defaults[$id] ?? ($field['default'] ?? null);
            $set = $config['package']['options'][$id] ?? null;

            return [$set ?? $default, $default, $set === null];
        }

        return [null, null, true];
    }

    /** @return array{es: ?string, en: ?string} */
    private function bothLocales(mixed $value): array
    {
        if (is_string($value)) {
            return ['es' => $value, 'en' => $value];
        }
        $value = is_array($value) ? $value : [];

        return ['es' => isset($value['es']) && is_string($value['es']) ? $value['es'] : null, 'en' => isset($value['en']) && is_string($value['en']) ? $value['en'] : null];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $config
     * @return array<string, array<string, mixed>> sections by type, base merged with the organizer's own
     */
    private function effectiveSections(array $content, array $config): array
    {
        $base = collect($content['sections'])->keyBy('type');
        $own = collect($config['sections'] ?? [])->keyBy('type');

        return $base->keys()->merge($own->keys())->unique()
            ->filter(fn ($type) => in_array($type, (array) config('invitation.section_types'), true))
            ->mapWithKeys(fn ($type) => [$type => array_merge($base[$type] ?? ['type' => $type, 'enabled' => false, 'order' => 99], $own[$type] ?? [])])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function sectionsPayload(Events $event, array $content, array $config): array
    {
        return collect($this->effectiveSections($content, $config))
            ->sortBy(fn (array $s) => $s['order'] ?? 99)
            ->map(function (array $s, string $type) use ($event) {
                $state = ($module = InvitationService::sectionModule($type)) ? $this->modules->stateOf($event, $module) : null;

                return [
                    'type' => $type,
                    // Effective: a section whose module is locked is not shown, whatever the template says.
                    'enabled' => (bool) ($s['enabled'] ?? false) && $state !== EventModules::LOCKED,
                    'order' => (int) ($s['order'] ?? 99),
                    'required' => in_array($type, (array) config('invitation.required_sections'), true),
                    'title' => $this->bothLocales($s['title'] ?? null),
                    'moduleState' => $state,
                ];
            })
            ->values()->all();
    }
}
