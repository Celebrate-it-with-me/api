<?php

use App\Http\Services\AppServices\SuggestedMusicExportService;
use App\Models\Events;
use App\Models\SuggestedMusic;
use App\Support\CsvCells;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function evilSong(Events $event): void
{
    SuggestedMusic::create([
        'event_id' => $event->id, 'title' => '=HYPERLINK("http://evil.test","x")', 'artist' => '+cmd|calc', 'album' => 'a', 'platform' => 'spotify',
        'platformId' => str_pad('1', 22, 'x', STR_PAD_LEFT), 'thumbnailUrl' => 'https://i.scdn.co/image/1', 'previewUrl' => 'https://p.scdn.co/1', 'suggested_by_entity' => 'user', 'suggested_by_id' => 999,
    ]);
}

it('prefixes formula-looking text and leaves numbers and normal text alone', function () {
    expect(CsvCells::cell('=1+1'))->toBe("'=1+1")
        ->and(CsvCells::cell('+13055550101'))->toBe("'+13055550101")
        ->and(CsvCells::cell('-x'))->toBe("'-x")
        ->and(CsvCells::cell('@sum'))->toBe("'@sum")
        ->and(CsvCells::cell("\tx"))->toBe("'\tx")
        ->and(CsvCells::cell("\rx"))->toBe("'\rx")
        ->and(CsvCells::cell('Salón'))->toBe('Salón')
        ->and(CsvCells::cell(-5.5))->toBe(-5.5)
        ->and(CsvCells::cell(0))->toBe(0)
        ->and(CsvCells::cell(''))->toBe('')
        ->and(CsvCells::row(['=a', 3, 'b']))->toBe(["'=a", 3, 'b']);
});

it('writes the suggested music CSV without live formulas', function () {
    $event = Events::factory()->create();
    evilSong($event);

    $csv = (function () use ($event) {
        ob_start();
        app(SuggestedMusicExportService::class)->export($event, 'csv')->sendContent();

        return ob_get_clean();
    })();

    expect($csv)->toContain("\"'=HYPERLINK")->toContain("'+cmd|calc")->not->toContain("\n=HYPERLINK")->not->toContain(',=HYPERLINK');
});

it('writes the suggested music XLSX as text with the quote prefix', function () {
    $event = Events::factory()->create();
    evilSong($event);

    $response = app(SuggestedMusicExportService::class)->export($event, 'xlsx');
    $sheet = IOFactory::load($response->getFile()->getPathname())->getActiveSheet();

    expect($sheet->getCell('A2')->getDataType())->toBe('s')
        ->and($sheet->getCell('A2')->getValue())->toBe('=HYPERLINK("http://evil.test","x")')
        ->and($sheet->getStyle('A2')->getQuotePrefix())->toBeTrue()
        ->and($sheet->getStyle('B2')->getQuotePrefix())->toBeTrue();
});
