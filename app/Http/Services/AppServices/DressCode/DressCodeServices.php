<?php

namespace App\Http\Services\AppServices\DressCode;

use App\Models\DressCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DressCodeServices
{
    /**
     * Retrieve the dress code associated with the given event, including any related dress code images.
     *
     * @param mixed $event The event instance to retrieve the dress code for.
     * @return mixed The dress code information with associated images.
     */
    public function getDressCodeByEvent($event): mixed
    {
        return $event->dressCode()->with('dressCodeImages')->first();
    }
    
    /**
     * Create a new dress code for the specified event with the provided data.
     * @param $events
     * @param $data
     * @return DressCode|Model
     */
    public function createDressCode($event, $data): Model|DressCode
    {
       
        $dressCode = DressCode::query()->create([
            'event_id' => $event->id,
            'dress_code_type' => $data['dressCodeType'],
            'description' => $data['description'] ?? null,
            'reserved_colors' => isset($data['reservedColors'])
                ? json_decode($data['reservedColors'], true) : [],
        ]);
        
        if (isset($data['dressCodeImages']) && is_array($data['dressCodeImages'])) {
            $this->addNewImages($data['dressCodeImages'], $dressCode);
        }
        
        return $dressCode;
    }
    
    /**
     * Update the details of a given dress code including its type, description, reserved colors, and associated images.
     *
     * @param DressCode $dressCode The dress code instance to be updated.
     * @param array $data The data used to update the dress code, which may include type, description, reserved colors, and images.
     * @return DressCode The updated dress code instance.
     */
    public function updateDressCode(DressCode $dressCode, $data): DressCode
    {
        $dressCode->update([
            'dress_code_type' => $data['dressCodeType'],
            'description' => $data['description'] ?? null,
            'reserved_colors' => json_decode($data['reservedColors'], true) ?? [],
        ]);
        
        // Process images
        $imagesToKeep = [];
        
        // Handle existing images to keep
        if (isset($data['existingImageIds']) && is_string($data['existingImageIds'])) {
            $imagesToKeep = json_decode($data['existingImageIds'], true) ?? [] ;
        }
        
        // Get existing images that need to be deleted
        $imagesToDelete = $dressCode->dressCodeImages()
            ->whereNotIn('id', $imagesToKeep)
            ->get();
        
        // Delete unwanted images from storage and database
        foreach ($imagesToDelete as $imageToDelete) {
            $disk = 'public';
            
            // Delete the file from storage if it exists
            if (Storage::disk($disk)->exists($imageToDelete->image_path)) {
                Storage::disk($disk)->delete($imageToDelete->image_path);
            }
            
            // Delete the database record
            $imageToDelete->delete();
        }
        
        // Process new images if they exist
        if (isset($data['dressCodeImages']) && is_array($data['dressCodeImages'])) {
            // Add new images
            $this->addNewImages($data['dressCodeImages'], $dressCode);
        }
        
        return $dressCode;
    }
    
    
    /**
     * @param $dressCodeImages
     * @param DressCode $dressCode
     * @return void
     */
    private function addNewImages($dressCodeImages, DressCode $dressCode): void
    {
        foreach ($dressCodeImages as $image) {
            $disk = 'public';
            
            // Generate a unique filename if it's a file upload
            if ($image instanceof UploadedFile) {
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('dress-codes', $filename, $disk);
            } else {
                // If it's already a file path or some other format
                $path = Storage::disk($disk)->put('dress-codes', $image);
            }
            
            // Create new image record
            $dressCode->dressCodeImages()->create([
                'image_path' => $path
            ]);
        }
    }
    
    /**
     * Delete a dress code and all associated images.
     *
     * @param DressCode $dressCode The dress code to delete
     * @return bool Whether the deletion was successful
     */
    public function deleteDressCode(DressCode $dressCode): bool
    {
        try {
            // Get all images associated with this dress code
            $dressCodeImages = $dressCode->dressCodeImages;
            
            // Delete image files from storage
            foreach ($dressCodeImages as $image) {
                // Determine which disk the image is stored on
                $disk = 'public';
                
                // Delete the file from storage if it exists
                if (Storage::disk($disk)->exists($image->image_path)) {
                    Storage::disk($disk)->delete($image->image_path);
                }
            }
            
            // Delete the dress code record (this will cascade delete related images if configured properly)
            return $dressCode->delete();
            
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Failed to delete dress code: ' . $e->getMessage());
            return false;
        }
    }
}
