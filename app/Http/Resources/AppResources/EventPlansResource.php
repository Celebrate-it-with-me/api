<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventPlansResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'slug' => $this->slug,
            'hasGallery' => $this->has_gallery,
            'hasMusic' => $this->has_music,
            'hasCustomDesign' => $this->has_custom_design,
            'hasDragEditor' => $this->has_drag_editor,
            'hasAiAssistant' => $this->has_ai_assistant,
            'hasInvitations' => $this->has_invitations,
            'hasSms' => $this->has_sms,
            'hasGiftRegistry' => $this->has_gift_registry,
            'supportLevel' => $this->support_level,
        ];
    }
}
