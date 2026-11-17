<?php

use App\Support\TemplatePackage\BlockCatalog;
use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

/** Warnings of one code with the block each one names. */
function moduleWarnings(array $result, string $code = 'module_block_missing'): array
{
    return array_values(array_map(
        fn (array $w) => $w['detail'] ?? null,
        array_filter($result['report']['warnings'], fn (array $w) => $w['code'] === $code)
    ));
}

function layoutWith(string $blocks): string
{
    return "<div class=\"pkg-frame\">\n  <q-sections></q-sections>\n  {$blocks}\n</div>\n";
}

it('lists the module blocks in one constant', function () {
    expect(BlockCatalog::MODULE_BLOCKS)->toBe(['comments', 'tables', 'suggested-music']);
});

it('warns once per missing module block and stays valid', function () {
    $r = PackageBuilder::good()->validate();

    expect($r['valid'])->toBeTrue()
        ->and(PackageBuilder::errors($r))->toBe([])
        ->and(moduleWarnings($r))->toBe(['comments', 'tables', 'suggested-music'])
        ->and(collect($r['report']['warnings'])->firstWhere('code', 'module_block_missing')['message'])
        ->toContain('<q-comments>')->toContain('no se verá con tu diseño');
});

it('does not warn when every module block is present', function () {
    $r = PackageBuilder::good()
        ->with('layout.html', layoutWith('<q-comments></q-comments><q-tables></q-tables><q-suggested-music></q-suggested-music>'))
        ->validate();

    expect($r['valid'])->toBeTrue()->and(moduleWarnings($r))->toBe([]);
});

it('warns only for the block that is missing', function () {
    $r = PackageBuilder::good()
        ->with('layout.html', layoutWith('<q-comments></q-comments>'))
        ->validate();

    expect($r['valid'])->toBeTrue()->and(moduleWarnings($r))->toBe(['tables', 'suggested-music']);
});

it('leaves a section file without its block to section_without_block alone', function () {
    $r = PackageBuilder::good()
        ->json('sections.json', function (array $lists) {
            foreach ($lists as $event => $sections) {
                $sections[] = ['type' => 'comments', 'enabled' => false, 'order' => 11, 'title' => ['es' => 'Mensajes', 'en' => 'Messages'], 'text' => ['es' => null, 'en' => null], 'settings' => (object) []];
                $lists[$event] = $sections;
            }

            return $lists;
        })
        ->with('sections/comments.html', '<div class="pkg-c"><p>Hi</p></div>')
        ->validate();

    expect($r['valid'])->toBeTrue()
        ->and(moduleWarnings($r, 'section_without_block'))->toHaveCount(1)
        ->and(moduleWarnings($r))->toBe(['tables', 'suggested-music']);
});

it('keeps the module map and the block list in sync', function () {
    expect(array_values(BlockCatalog::MODULE_BLOCK_BY_MODULE))->toBe(BlockCatalog::MODULE_BLOCKS);
});
