<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion;
use App\Models\TemplatePackage;
use App\Support\TemplatePackage\BlockCatalog;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Which governed modules (BlockCatalog::MODULE_BLOCK_BY_MODULE) a template can show. Native templates (no package)
 * support all of them; a package supports a module when some HTML of it carries the module's `<q-*>` block. The answer
 * for a package is computed at install and kept in `template_packages.supported_modules`.
 */
class ModuleSupport
{
    /**
     * Modules whose block appears in the AST of a package (layout or any section).
     *
     * @param  array<string, mixed>  $ast  `{"layout": tree|null, "sections": {type: tree}}`
     * @return list<string>
     */
    public static function fromAst(array $ast): array
    {
        $names = [];
        $walk = function (mixed $node) use (&$walk, &$names): void {
            if (! is_array($node)) {
                return;
            }
            if (($node['t'] ?? null) === 'q' && is_string($node['name'] ?? null)) {
                $names[$node['name']] = true;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($ast);

        $supported = [];
        foreach (BlockCatalog::MODULE_BLOCK_BY_MODULE as $module => $block) {
            if (isset($names[$block])) {
                $supported[] = $module;
            }
        }

        return $supported;
    }

    /** Governed modules the package does not support; `[]` for no package. @return list<string> */
    public static function missingFor(?TemplatePackage $package): array
    {
        if ($package === null) {
            return [];
        }

        return array_values(array_diff(array_keys(BlockCatalog::MODULE_BLOCK_BY_MODULE), (array) $package->supported_modules));
    }

    public static function isGoverned(string $module): bool
    {
        return isset(BlockCatalog::MODULE_BLOCK_BY_MODULE[$module]);
    }

    /**
     * The package template the event draws now and the module it lacks, or null when the module may be switched on
     * (native template, ungoverned module, or the package supports it).
     *
     * @return array{slug: string, name: string}|null the template that lacks the module
     */
    public function blockingTemplate(Events $event, string $module): ?array
    {
        if (! self::isGoverned($module)) {
            return null;
        }

        $template = app(InvitationService::class)->templateFor($event);
        $version = $event->template_version_id
            ? InvitationTemplateVersion::query()->with('package')->find($event->template_version_id)
            : $template->currentVersion()->with('package')->first();
        $package = $version?->package;

        if ($package === null || ! in_array($module, self::missingFor($package), true)) {
            return null;
        }

        return ['slug' => $template->slug, 'name' => $this->templateName($template, $package)];
    }

    /**
     * Switching a governed module on: 409 `template_missing_module` when the event's template lacks its block.
     * Switching off, native templates and ungoverned modules never fail.
     */
    public function assertCanActivate(Events $event, string $module): void
    {
        if (($template = $this->blockingTemplate($event, $module)) === null) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'code' => 'template_missing_module',
            'message' => 'La plantilla de tu invitación no incluye este módulo. Cambia de plantilla para activarlo.',
            'module' => $module,
            'template' => $template,
        ], 409));
    }

    private function templateName(InvitationTemplate $template, TemplatePackage $package): string
    {
        return $template->name ?: ($package->manifest['name']['es'] ?? $template->slug);
    }
}
