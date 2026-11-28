<?php

use App\Exports\GuestMenuExport;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

it('writes guest names as text, never as formulas (I-V5-6)', function () {
    $event = Events::factory()->create();
    Guest::factory()->create(['event_id' => $event->id, 'name' => '=HYPERLINK("http://evil.test","click")', 'email' => '+cmd@x.test', 'rsvp_status' => 'attending']);

    $path = tempnam(sys_get_temp_dir(), 'menu') . '.xlsx';
    file_put_contents($path, Excel::raw(new GuestMenuExport($event), ExcelWriter::XLSX));
    $sheet = IOFactory::load($path)->getActiveSheet();
    @unlink($path);

    expect($sheet->getCell('A2')->getDataType())->toBe('s')
        ->and($sheet->getCell('A2')->getValue())->toBe('=HYPERLINK("http://evil.test","click")')
        ->and($sheet->getStyle('A2')->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('B2')->getDataType())->toBe('s');
});
