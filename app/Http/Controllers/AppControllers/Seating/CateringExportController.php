<?php

namespace App\Http\Controllers\AppControllers\Seating;

use App\Exports\Catering\CateringExport;
use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\Seating\CateringReport;
use App\Models\Events;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * The list for the caterer and the venue (dishes chosen, by table), as XLSX or a printable PDF. The route group
 * gives `view_event` (a viewer can download; a non-member gets 404). No phone or email in the file.
 */
class CateringExportController extends Controller
{
    public function __construct(private readonly CateringReport $report) {}

    /** Query: `format` xlsx (default) | pdf, `locale` es | en (default: the event's), `include_pending` 0 | 1 (default 0). */
    public function __invoke(Request $request, Events $event): Response
    {
        $data = $request->validate([
            'format' => ['nullable', 'string', 'in:xlsx,pdf'],
            'locale' => ['nullable', 'string', 'in:es,en'],
            'include_pending' => ['nullable', 'boolean'],
        ]);

        $locale = $data['locale'] ?? ($event->locale === 'en' ? 'en' : 'es');
        $format = $data['format'] ?? 'xlsx';
        $report = $this->report->build($event, (bool) ($data['include_pending'] ?? false), $locale);
        $name = 'catering-' . (Str::slug($event->custom_url_slug ?: (string) $event->event_name) ?: 'event-' . $event->id) . '-' . now()->format('Y-m-d') . ".$format";

        if ($format === 'pdf') {
            $generated = now()->locale($locale)->translatedFormat($locale === 'es' ? 'j \d\e F \d\e Y, H:i' : 'F j, Y, H:i');

            $pdf = Pdf::loadView('exports.catering', ['r' => $report])->setPaper('a4');
            $pdf->render();

            // Footer on every page ("generated on ... | page x of y"), drawn on the canvas after the layout is known.
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $label = fn (string $key) => (string) __("exports.catering.$key", [], $locale);
            $canvas->page_text(40, $canvas->get_height() - 34, $label('generated') . " $generated   |   " . $label('page') . ' {PAGE_NUM} ' . $label('of') . ' {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.43, 0.39, 0.38]);

            return $pdf->download($name);
        }

        return Excel::download(new CateringExport($report), $name);
    }
}
