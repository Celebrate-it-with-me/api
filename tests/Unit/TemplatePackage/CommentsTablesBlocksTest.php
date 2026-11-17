<?php

use App\Http\Services\AppServices\InvitationService;
use App\Support\TemplatePackage\BlockCatalog;
use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

/** The good package, now designing the two new sections too (in both event types). */
function packageWithCommentsAndTables(string $comments = '<div class="pkg-c"><q-comments variant="cards" max="5"></q-comments></div>', string $tables = '<div class="pkg-t"><q-tables variant="card"></q-tables></div>'): PackageBuilder
{
    return PackageBuilder::good()
        ->json('sections.json', function (array $lists) {
            foreach ($lists as $event => $sections) {
                $sections[] = ['type' => 'tables', 'enabled' => false, 'order' => 10, 'title' => ['es' => 'Tu mesa', 'en' => 'Your table'], 'text' => ['es' => null, 'en' => null], 'settings' => (object) []];
                $sections[] = ['type' => 'comments', 'enabled' => false, 'order' => 11, 'title' => ['es' => 'Mensajes', 'en' => 'Messages'], 'text' => ['es' => null, 'en' => null], 'settings' => (object) []];
                $lists[$event] = $sections;
            }

            return $lists;
        })
        ->with('sections/comments.html', $comments)
        ->with('sections/tables.html', $tables);
}

it('registers the comments and tables sections and blocks in the catalog', function () {
    expect(BlockCatalog::SECTION_TYPES)->toContain('comments', 'tables')
        ->and(BlockCatalog::BLOCKS['comments'])->toMatchArray(['max' => 1, 'scope' => 'any'])
        ->and(BlockCatalog::BLOCKS['tables'])->toMatchArray(['max' => 1, 'scope' => 'any'])
        ->and(array_keys(BlockCatalog::BLOCKS['comments']['props']))->toBe(['frame', 'variant', 'max'])
        ->and(array_keys(BlockCatalog::BLOCKS['tables']['props']))->toBe(['frame', 'variant']);
});

it('keeps the three lists of section types in step', function () {
    $service = new ReflectionClassConstant(InvitationService::class, 'SECTION_TYPES');
    $config = config('invitation.section_types');

    foreach (['comments', 'tables'] as $type) {
        expect($service->getValue())->toContain($type)
            ->and($config)->toContain($type)
            ->and(BlockCatalog::SECTION_TYPES)->toContain($type);
    }
});

it('accepts a package that designs both new sections with their blocks', function () {
    $r = packageWithCommentsAndTables()->validate();

    expect(PackageBuilder::errors($r))->toBe([])
        ->and($r['valid'])->toBeTrue()
        ->and(PackageBuilder::warnings($r))->not->toContain('section_without_block');
});

it('still accepts the package without any of the new sections', function () {
    $r = PackageBuilder::good()->validate();

    expect($r['valid'])->toBeTrue()->and(PackageBuilder::errors($r))->toBe([]);
});

it('refuses a second block of the same kind in one file', function (string $file, string $html) {
    $r = packageWithCommentsAndTables()->with($file, $html)->validate();

    expect(PackageBuilder::errors($r))->toContain('block_too_many');
})->with([
    'comments' => ['sections/comments.html', '<q-comments></q-comments><q-comments></q-comments>'],
    'tables' => ['sections/tables.html', '<q-tables></q-tables><q-tables></q-tables>'],
]);

it('refuses a prop value outside the closed list', function (string $file, string $html) {
    $r = packageWithCommentsAndTables()->with($file, $html)->validate();

    expect(PackageBuilder::errors($r))->toContain('block_prop_invalid');
})->with([
    'comments variant' => ['sections/comments.html', '<q-comments variant="carousel"></q-comments>'],
    'comments max too big' => ['sections/comments.html', '<q-comments max="21"></q-comments>'],
    'comments max zero' => ['sections/comments.html', '<q-comments max="0"></q-comments>'],
    'comments frame' => ['sections/comments.html', '<q-comments frame="wide"></q-comments>'],
    'tables variant' => ['sections/tables.html', '<q-tables variant="list"></q-tables>'],
]);

it('refuses children inside the new blocks', function () {
    $r = packageWithCommentsAndTables()->with('sections/comments.html', '<q-comments>hola</q-comments>')->validate();

    expect(PackageBuilder::errors($r))->toContain('block_children');
});

it('warns, without failing, about a section file that lacks its block', function (string $file) {
    $r = packageWithCommentsAndTables()->with($file, '<div class="pkg-x"><p>{{ section.title }}</p></div>')->validate();

    expect($r['valid'])->toBeTrue()
        ->and(PackageBuilder::errors($r))->toBe([])
        ->and(PackageBuilder::warnings($r))->toContain('section_without_block');
})->with(['sections/comments.html', 'sections/tables.html']);

it('lets a stylesheet target the new blocks through their system hooks', function () {
    $css = file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/theme.css') . "\n.inv-q--comments{border-radius:12px}\n.inv-q--tables{padding:8px}\n";
    $r = packageWithCommentsAndTables()->with('theme.css', $css)->validate();

    expect(PackageBuilder::errors($r))->toBe([]);
});
