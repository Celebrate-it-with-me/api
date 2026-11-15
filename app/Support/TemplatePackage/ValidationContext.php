<?php

namespace App\Support\TemplatePackage;

/**
 * What the validator must know about the world outside the zip: the packages already installed (version, level and
 * visibility per slug, to enforce `version_not_greater`, `slug_taken` and `visibility_changed`), the fields and
 * variants of the previous version (to warn about removals), the engine level and the registered level 2 slugs.
 */
final class ValidationContext
{
    /**
     * @param  array<string, string>  $installed  slug => highest installed semver
     * @param  array<string, int>  $levels  slug => level of the installed package
     * @param  array<string, string>  $visibilities  slug => visibility of the installed package
     * @param  array<string, array{fields: list<string>, variants: array<string, list<string>>}>  $previous  slug => the highest installed version's editable ids and variant values
     * @param  list<string>  $level2Slugs
     */
    public function __construct(
        public array $installed = [],
        public array $levels = [],
        public array $visibilities = [],
        public array $previous = [],
        public ?int $engine = null,
        public ?array $level2Slugs = null,
        public bool $allowEngineFonts = false,
        public bool $allowReservedSlugs = false,
    ) {
        $this->engine ??= (int) config('invitation.engine', 1);
        $this->level2Slugs ??= (array) config('invitation.level2_slugs', []);
    }
}
