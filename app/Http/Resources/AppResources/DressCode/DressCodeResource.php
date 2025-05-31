<?php

namespace App\Http\Resources\AppResources\DressCode;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DressCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dressCodeId' => $this->dress_code_id,
            'imagePath' => $this->image_path,
        ];
    }
}
