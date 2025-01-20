<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class GalleryImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $disk = (app()->environment('local'))
            ? 'local'
            : 's3';

        return [
            'id' => $this->id,
            'url' => url(Storage::disk($disk)->url($this->image_path)),
            'thumbnail' => url(Storage::disk($disk)->url($this->thumbnail_path)),
            'title' => 'test'
        ];
    }
}
