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
        Log::debug("SweetMemoriesImageResource::toArray()", [
                'driver' => config('filesystems.disks.sweet-memories.driver'),
                'env_value' => env('SWEET_MEMORIES_DRIVER'),
                'filesystem_disk' => config('filesystems.default'),
            ]
        );
        
        return [
            'id' => $this->id,
            'imagePath' => Storage::disk(self::STORAGE_DISK)->url($this->image_path),
            'imageName' => $this->image_name,
            'thumbnailPath' => Storage::disk(self::STORAGE_DISK)->url($this->thumbnail_path),
            'thumbnailName' => $this->thumbnail_name,
            'imageOriginalName' => $this->image_original_name,
            'imageSize' => $this->image_size,
        ];
    }
}
