<?php

namespace App\Http\Resources\AppResources\DressCode;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DressCodeImageResource extends JsonResource
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
            'eventId' => $this->event_id,
            'dressCodeType' => $this->dress_code_type,
            'description' => $this->description,
            'reservedColors' => $this->reserved_colors,
            'dressCodeImages' => DressCodeImageResource::collection($this->whenLoaded('dressCodeImages')),
        ];
    }
}
