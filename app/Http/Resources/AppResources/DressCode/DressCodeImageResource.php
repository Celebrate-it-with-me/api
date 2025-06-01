<?php

namespace App\Http\Resources\AppResources\DressCode;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DressCodeImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $disk = 'public';

        return [
            'id' => $this->id,
            'dressCodeId' => $this->dress_code_id,
            'imagePath' => Storage::disk($disk)->url($this->image_path),
        ];
    }
}
