<?php

namespace App\Http\Resources\AppResources\EventActivity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventActivityResource extends JsonResource
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
            'type' => $this->type,
            'message' => $this->message,
            // Params to build the sentence in the app's own language; `message` is the same sentence rendered by the API.
            'params' => $this->params,
            'meta' => $this->meta,
            'icon' => $this->icon,
            'color' => $this->color,
            // `createdAt` is relative text in the request language; `occurredAt` is the moment (UTC ISO 8601) to format in the app.
            'createdAt' => $this->created_at->diffForHumans(),
            'occurredAt' => $this->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
