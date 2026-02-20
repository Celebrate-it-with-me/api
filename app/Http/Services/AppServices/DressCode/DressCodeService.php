<?php

namespace App\Http\Services\AppServices\DressCode;

use App\Models\DressCode;
use App\Models\DressCodeImage;
use App\Models\Events;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DressCodeService
{
    /**
     * Get dress code data for an event.
     */
    public function getDressCode(int $eventId): array
    {
        $event = Events::findOrFail($eventId);
        $dressCode = $event->dressCode()->with('dressCodeImages')->first();

        if (!$dressCode) {
            return [
                'id' => null,
                'dressCodeType' => '',
                'description' => '',
                'reservedColors' => [],
                'dressCodeImages' => []
            ];
        }

        return [
            'id' => $dressCode->id,
            'dressCodeType' => $dressCode->dress_code_type,
            'description' => $dressCode->description,
            'reservedColors' => $dressCode->reserved_colors,
            'dressCodeImages' => $dressCode->dressCodeImages->map(function ($image) {
                return [
                    'id' => $image->id,
                    'imagePath' => Storage::disk('public')->url($image->image_path),
                    'originalName' => $image->original_name
                ];
            })->toArray()
        ];
    }

    /**
     * Create a new dress code.
     */
    public function createDressCode(int $eventId, array $data, ?array $images = []): DressCode
    {
        $event = Events::findOrFail($eventId);

        if ($event->dressCode()->exists()) {
            throw new \Exception('Dress code already exists for this event.', 422);
        }

        $dressCode = DressCode::create([
            'event_id' => $event->id,
            'dress_code_type' => $data['dressCodeType'],
            'description' => $data['description'] ?? null,
            'reserved_colors' => json_decode($data['reservedColors'], true),
        ]);

        if ($images) {
            foreach ($images as $file) {
                $this->storeImage($file, $dressCode->id);
            }
        }

        return $dressCode;
    }

    /**
     * Update an existing dress code.
     */
    public function updateDressCode(int $eventId, int $dressCodeId, array $data, ?array $images = []): DressCode
    {
        $dressCode = DressCode::where('event_id', $eventId)->findOrFail($dressCodeId);

        $dressCode->update([
            'dress_code_type' => $data['dressCodeType'],
            'description' => $data['description'] ?? null,
            'reserved_colors' => json_decode($data['reservedColors'], true),
        ]);

        // Handle Image Deletion
        $existingImageIds = json_decode($data['existingImageIds'] ?? '[]', true);
        $imagesToDelete = $dressCode->dressCodeImages()
            ->whereNotIn('id', $existingImageIds)
            ->get();

        foreach ($imagesToDelete as $image) {
            Storage::disk('public')->delete($image->image_path);
            $image->delete();
        }

        // Handle New Image Uploads
        if ($images) {
            foreach ($images as $file) {
                $this->storeImage($file, $dressCode->id);
            }
        }

        return $dressCode;
    }

    /**
     * Store a dress code image.
     */
    private function storeImage(UploadedFile $file, int $dressCodeId): void
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('dress-code-images', $filename, 'public');

        DressCodeImage::query()->create([
            'dress_code_id' => $dressCodeId,
            'image_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    /**
     * Generate mock AI images.
     */
    public function generateMockImages(): array
    {
        return [
            'dress-code-images/ai_generated_1.jpg',
            'dress-code-images/ai_generated_2.jpg',
            'dress-code-images/ai_generated_3.jpg',
        ];
    }
    
    /**
     * Delete a dress code associated with a specific event.
     */
    public function deleteDressCode(int $eventId, int $dressCodeId): true
    {
        $dressCode = DressCode::query()
            ->where('event_id', $eventId)
            ->where('id', $dressCodeId)
            ->firstOrFail();
        
        $dressCode->delete();
        return true;
    }
}
