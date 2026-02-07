<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SweetMemoriesImageResource extends JsonResource
{
    protected const STORAGE_DISK = 'sweet-memories';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'imagePath' => Storage::disk(self::STORAGE_DISK)->url($this->image_path),
            'imageName' => $this->image_name,
            'thumbnailPath' => Storage::disk(self::STORAGE_DISK)->url($this->thumbnail_path),
            'thumbnailName' => $this->thumbnail_name,
            'imageOriginalName' => $this->image_original_name,
            'imageSize' => $this->image_size,
            'title' => $this->title,
            'description' => $this->description,
            'visible' => $this->visible,
            'order' => $this->order,
            'year' => $this->year
        ];
    }
}
