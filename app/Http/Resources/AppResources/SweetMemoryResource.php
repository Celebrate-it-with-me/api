<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SweetMemoryResource extends JsonResource
{
    protected const STORAGE_DISK = 'sweet-memories';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        Log::debug(
            'SweetMemoryResource::toArray()',
            [
                'driver' => config('filesystems.disks.sweet-memories.driver'),
                'env_value' => env('SWEET_MEMORIES_DRIVER'),
                'filesystem_disk' => config('filesystems.default'),
            ]
        );

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'title' => $this->title,
            'description' => $this->description,
            'year' => $this->year,
            'visible' => $this->visible,
            'imageUrl' => $this->image_path ? Storage::disk(self::STORAGE_DISK)->url($this->image_path) : null,
        ];
    }
}
