<?php

namespace App\Jobs;

use App\Models\EventLocation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ProcessGooglePlacePhotos implements ShouldQueue
{
    use Queueable;

    protected EventLocation $eventLocation;

    protected array $photoReferences;

    /**
     * Create a new job instance.
     */
    public function __construct(EventLocation $eventLocation, array $photoReferences)
    {
        $this->eventLocation = $eventLocation;
        $this->photoReferences = $photoReferences;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->photoReferences as $reference) {
            $response = Http::withOptions(['allow_redirects' => true])
                ->get(config('services.google.url') . '/place/photo', [
                    'maxwidth' => 1024,
                    'photoreference' => $reference,
                    'key' => config('services.google.map_key'),
                ]);

            if ($response->successful()) {
                $filename = 'locations/google/' . $this->eventLocation->id . '/' . uniqid('photo_') . '.jpg';
                Storage::disk('public')->put($filename, $response->body(), 'public');

                $this->eventLocation->eventLocationImages()->create([
                    'path' => Storage::disk('public')->url($filename),
                    'caption' => null,
                    'order' => 0,
                    'source' => 'google',
                ]);
            }
        }
    }
}
