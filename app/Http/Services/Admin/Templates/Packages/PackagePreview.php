<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Http\Services\Admin\Templates\TemplatePreview;
use App\Http\Services\AppServices\PackagePayload;
use App\Models\InvitationTemplateVersion as Version;

/**
 * The preview of a package that is not (or not yet) live: a staged upload or an installed package, drawn with the sample
 * data of the admin preview and the very payload an event would get (`theme.package` points to its staged or final files).
 */
class PackagePreview
{
    public function __construct(private readonly PackageStage $stage, private readonly PackageQuery $query, private readonly PackagePayload $payload) {}

    /**
     * @param  array<string, mixed>  $data  validated PreviewRequest
     * @return array<string, mixed>
     */
    public function build(array $data, TemplatePreview $preview): array
    {
        $type = $data['eventType'];

        if (! empty($data['stage_token'])) {
            $meta = $this->stage->load($data['stage_token']);
            if (! $meta['valid']) {
                throw PackageError::invalid('stage_token', 'invalid_report', 'La subida tiene errores: no se puede previsualizar.');
            }
            $json = $meta['build']['json'];
            $sections = $json['sections'][$type] ?? null;
            $theme = PackageBuild::theme($json['manifest']['slug'], $json['tokens']);
            $fragment = $this->payload->fragmentForStage($meta, $data['stage_token']);
        } else {
            $package = $this->query->find((int) $data['package_id']);
            $version = Version::query()->where('package_id', $package->id)->whereHas('template', fn ($q) => $q->where('slug', "{$package->slug}-{$type}"))->first();
            $sections = $version?->sections;
            $theme = $version?->theme;
            $fragment = $this->payload->fragment($package->id);
        }

        if ($sections === null) {
            throw PackageError::invalid('eventType', 'not_supported', 'El paquete no declara este tipo de evento.');
        }
        if (! empty($data['preset'])) {
            if (! isset($theme['presets'][$data['preset']])) {
                throw PackageError::invalid('preset', 'invalid', 'El paquete no tiene ese preajuste.');
            }
            $theme['preset'] = $data['preset'];
        }

        $composed = $this->payload->compose($fragment);
        if ($composed === null) {
            throw PackageError::invalid('package', 'engine_too_new', 'El motor actual es más viejo que el que pide el paquete.');
        }

        return $preview->build($sections, $theme, $data['locale'], $type, $composed, $data['state'] ?? 'invitation');
    }
}
