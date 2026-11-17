<?php

use App\Http\Services\AppServices\InvitationService;
use App\Support\TemplatePackage\BlockCatalog;
use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

/** The good package, now designing the suggested music section too (in both event types). */
function packageWithSuggestedMusic(string $html = '<div class="pkg-m"><q-suggested-music variant="cards"></q-suggested-music></div>'): PackageBuilder
{
    return PackageBuilder::good()
        ->json('sections.json', function (array $lists) {
            foreach ($lists as $event => $sections) {
                $sections[] = ['type' => 'suggested_music', 'enabled' => false, 'order' => 12, 'title' => ['es' => 'Tu música', 'en' => 'Your music'], 'text' => ['es' => null, 'en' => null], 'settings' => (object) []];
                $lists[$event] = $sections;
            }

            return $lists;
        })
        ->with('sections/suggested_music.html', $html);
}

it('registers the suggested music section and block in the catalog', function () {
    expect(BlockCatalog::SECTION_TYPES)->toContain('suggested_music')
        ->and(BlockCatalog::BLOCKS['suggested-music'])->toMatchArray(['max' => 1, 'scope' => 'any'])
        ->and(array_keys(BlockCatalog::BLOCKS['suggested-music']['props']))->toBe(['frame', 'variant'])
        ->and(BlockCatalog::BLOCKS['suggested-music']['props']['variant'])->toBe(['enum', ['list', 'cards']])
        ->and(BlockCatalog::SECTION_BLOCKS['suggested_music'])->toBe('suggested-music')
        ->and(BlockCatalog::MODULE_BLOCK_BY_MODULE['suggested_music'])->toBe('suggested-music')
        ->and(BlockCatalog::MODULE_BLOCKS)->toContain('suggested-music');
});

it('keeps the three lists of section types in step', function () {
    $service = new ReflectionClassConstant(InvitationService::class, 'SECTION_TYPES');
    $modules = new ReflectionClassConstant(InvitationService::class, 'SECTION_MODULES');

    expect($service->getValue())->toContain('suggested_music')
        ->and(config('invitation.section_types'))->toContain('suggested_music')
        ->and(BlockCatalog::SECTION_TYPES)->toContain('suggested_music')
        ->and($modules->getValue()['suggested_music'])->toBe('suggested_music');
});

it('accepts a package that designs the section with its block, and one that does not', function () {
    $with = packageWithSuggestedMusic()->validate();
    $without = PackageBuilder::good()->validate();

    expect(PackageBuilder::errors($with))->toBe([])
        ->and($with['valid'])->toBeTrue()
        ->and(PackageBuilder::warnings($with))->not->toContain('section_without_block')
        ->and($without['valid'])->toBeTrue();
});

it('refuses a second block, children, and values outside the closed lists', function (string $html, string $code) {
    expect(PackageBuilder::errors(packageWithSuggestedMusic($html)->validate()))->toContain($code);
})->with([
    'two blocks' => ['<q-suggested-music></q-suggested-music><q-suggested-music></q-suggested-music>', 'block_too_many'],
    'variant' => ['<q-suggested-music variant="carousel"></q-suggested-music>', 'block_prop_invalid'],
    'frame' => ['<q-suggested-music frame="wide"></q-suggested-music>', 'block_prop_invalid'],
    'children' => ['<q-suggested-music>hola</q-suggested-music>', 'block_children'],
]);

it('warns, without failing, about a section file that lacks its block', function () {
    $r = packageWithSuggestedMusic('<div class="pkg-x"><p>{{ section.title }}</p></div>')->validate();

    expect($r['valid'])->toBeTrue()
        ->and(PackageBuilder::errors($r))->toBe([])
        ->and(PackageBuilder::warnings($r))->toContain('section_without_block');
});

it('warns when no HTML of the package carries the block (module_block_missing)', function () {
    $without = PackageBuilder::good()->validate();
    $with = packageWithSuggestedMusic()->validate();
    $details = fn (array $r) => array_column(array_filter($r['report']['warnings'], fn ($w) => $w['code'] === 'module_block_missing'), 'detail');

    expect($details($without))->toContain('suggested-music')
        ->and($details($with))->not->toContain('suggested-music');
});

it('lets a stylesheet target the block and a link point at the section', function () {
    $css = file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/theme.css') . "\n.inv-q--suggested-music{border-radius:12px}\n";
    $r = packageWithSuggestedMusic('<div class="pkg-m"><a href="#suggested_music">Música</a><q-suggested-music></q-suggested-music></div>')->with('theme.css', $css)->validate();

    expect(PackageBuilder::errors($r))->toBe([]);
});

it('does not let the music behavior rules leak onto the suggested music block', function () {
    $r = packageWithSuggestedMusic('<div data-reveal="fade"><q-suggested-music></q-suggested-music></div>')->validate();

    expect(PackageBuilder::errors($r))->not->toContain('behavior_on_music');
});

it('still forbids a carousel wrapping the suggested music block', function () {
    $r = packageWithSuggestedMusic('<ul data-carousel="snap"><li><q-suggested-music></q-suggested-music></li></ul>')->validate();

    expect(PackageBuilder::errors($r))->toContain('behavior_scope');
});
