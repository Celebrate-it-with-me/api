<?php

namespace App\Http\Services\AppServices\SweetMemory;

use App\Models\Events;
use App\Models\SweetMemoriesImage;
use App\Models\SweetMemory;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SweetMemoryServices
{
    protected Request $request;

    protected SweetMemory $sweetMemory;

    const STORAGE_DISK = 'sweet-memories';

    /**
     * SweetMemoriesImageServices constructor.
     */
    public function __construct(
        Request $request,
        SweetMemory $sweetMemory
    ) {
        $this->request = $request;
        $this->sweetMemory = $sweetMemory;
    }

    /**
     * Create a new sweet configuration for the given event.
     */
    public function create(Events $event): SweetMemory
    {
        $path = '';
        try {
            $file = $this->request->file('images')[0] ?? null;
            
            if ($file) {
                $storagePath = "images/event/$event->id/memories-images";
                $path = $file->store($storagePath, self::STORAGE_DISK);

                //[$thumbnailPath, $thumbnailName] = $this->generateThumbnail($file, $event);
                //$metadata = $this->request->get('metadata');

                //Log::info('checking metadata', $metadata);
            }
            
            return SweetMemory::query()->create([
                'event_id' => $event->id,
                'title' => $this->request->get('title', 'Untitled'),
                'description' => $this->request->get('description', ''),
                'year' => $this->request->get('year', ''),
                'visible' => $this->request->input('visible') && $this->request->get('visible') === 'true',
                'image_path' => $path,
            ]);
        } catch (Exception $e) {
            Log::error('File upload error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Generate a thumbnail for the given file and event.
     *
     * @param  Events  $event
     */
    private function generateThumbnail($file, $event): array
    {
        $imageThumbnail = $this->imageManager->read($file->getPathname())
            ->resize(150, 150);

        $thumbnailName = 'thumbnail_' . $file->getClientOriginalName();
        $thumbnailPath = "images/event/$event->id/memories-thumbnails/$thumbnailName";

        Storage::disk(self::STORAGE_DISK)->put($thumbnailPath, (string) $imageThumbnail->encode());

        return [$thumbnailPath, $thumbnailName];
    }

    /**
     * Deletes a sweet memories image and its associated files from storage.
     */
    public function destroy(Events $event, SweetMemoriesImage $sweetMemoriesImage): array
    {
        try {
            $imagePath = $sweetMemoriesImage->image_path;
            $thumbnailPath = $sweetMemoriesImage->thumbnail_path;

            Storage::disk(self::STORAGE_DISK)->delete($imagePath);
            Storage::disk(self::STORAGE_DISK)->delete($thumbnailPath);

            $sweetMemoriesImage->delete();

            return [true, 'Sweet memories image deleted successfully', 200];
        } catch (Exception $e) {
            Log::error('Error deleting sweet memories image', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [false, 'Error deleting sweet memories image', 500];
        }
    }

    /**
     * Updates the name of the sweet memories image.
     *
     * @param  Request  $request  The HTTP request instance containing the new name.
     * @param  SweetMemoriesImage  $sweetMemoriesImage  The SweetMemoriesImage model instance to be updated.
     * @return array Returns an array with a success status (true/false) and an HTTP status code.
     */
    public function updateName(Request $request, SweetMemoriesImage $sweetMemoriesImage): array
    {
        try {
            $sweetMemoriesImage->update([
                'image_original_name' => $request->get('name'),
            ]);

            return [true, 200];
        } catch (Exception $e) {
            Log::error('Error updating sweet memories image name', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [false, 500];
        }
    }
}
