<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SuggestedMusic;
use App\Models\User;
use App\Models\Guest;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuggestedMusicExportService
{
    /**
     * Export suggested music for an event.
     *
     * @param Events $event
     * @param string $format
     * @return mixed
     */
    public function export(Events $event, string $format)
    {
        $data = $this->getExportData($event);

        return match ($format) {
            'csv' => $this->exportCsv($data, $event),
            'xlsx' => $this->exportXlsx($data, $event),
            'pdf' => $this->exportPdf($data, $event),
            default => throw new \InvalidArgumentException("Invalid format: {$format}"),
        };
    }

    /**
     * Get and sort data for export.
     */
    protected function getExportData(Events $event): Collection
    {
        return SuggestedMusic::query()
            ->where('event_id', $event->id)
            ->with(['suggestedBy'])
            ->withCount([
                'musicVotes as upvotes' => fn($q) => $q->where('vote_type', 'up'),
                'musicVotes as downvotes' => fn($q) => $q->where('vote_type', 'down'),
            ])
            ->get()
            ->map(function ($item) {
                $item->score = $item->upvotes - $item->downvotes;
                $item->suggested_by_name = $item->suggestedBy?->name ?? 'Unknown';
                $item->spotify_url = $item->platform === 'spotify' 
                    ? "https://open.spotify.com/track/{$item->platformId}" 
                    : $item->platformId;
                return $item;
            })
            ->sort(function ($a, $b) {
                if ($a->score === $b->score) {
                    return $b->created_at <=> $a->created_at;
                }
                return $b->score <=> $a->score;
            })
            ->values();
    }

    /**
     * Export to CSV (no extra dependencies required).
     */
    protected function exportCsv(Collection $data, Events $event): StreamedResponse
    {
        $filename = $this->getFilename($event, 'csv');
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            
            // CSV Header
            fputcsv($file, ['Title', 'Artist', 'Platform', 'URL', 'SuggestedBy', 'Score', 'Upvotes', 'Downvotes', 'CreatedAt']);

            // CSV Data
            foreach ($data as $item) {
                fputcsv($file, [
                    $item->title,
                    $item->artist,
                    $item->platform,
                    $item->spotify_url,
                    $item->suggested_by_name,
                    $item->score,
                    $item->upvotes,
                    $item->downvotes,
                    $item->created_at->toDateTimeString(),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export to XLSX using Maatwebsite/Excel.
     */
    protected function exportXlsx(Collection $data, Events $event)
    {
        $filename = $this->getFilename($event, 'xlsx');
        
        $exportData = $data->map(fn($item) => [
            'Title' => $item->title,
            'Artist' => $item->artist,
            'Platform' => $item->platform,
            'URL' => $item->spotify_url,
            'SuggestedBy' => $item->suggested_by_name,
            'Score' => $item->score,
            'Upvotes' => $item->upvotes,
            'Downvotes' => $item->downvotes,
            'CreatedAt' => $item->created_at->toDateTimeString(),
        ]);

        return Excel::download(new class($exportData) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
            protected $collection;
            public function __construct($collection) { $this->collection = $collection; }
            public function collection() { return $this->collection; }
            public function headings(): array {
                return ['Title', 'Artist', 'Platform', 'URL', 'SuggestedBy', 'Score', 'Upvotes', 'Downvotes', 'CreatedAt'];
            }
        }, $filename);
    }

    /**
     * Export to PDF using Barryvdh/DomPDF.
     */
    protected function exportPdf(Collection $data, Events $event)
    {
        $filename = $this->getFilename($event, 'pdf');
        
        $pdf = Pdf::loadView('exports.suggested_music', [
            'event' => $event,
            'data' => $data,
        ]);

        return $pdf->download($filename);
    }

    /**
     * Generate a meaningful filename.
     */
    protected function getFilename(Events $event, string $extension): string
    {
        $eventName = str_replace(' ', '_', strtolower($event->title));
        return "suggested_music_{$eventName}_" . date('Y-m-d') . ".{$extension}";
    }
}
