<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SweetMemoriesImage;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class SweetMemoriesImageServices
{
    protected Request $request;

    protected SweetMemoriesImage $sweetMemoriesImage;

    protected ImageManager $imageManager;

    const STORAGE_DISK = 'sweet-memories';

    /**
     * SweetMemoriesImageServices constructor.
     */
    public function __construct(
        Request $request,
        ImageManager $imageManager,
        SweetMemoriesImage $sweetMemoriesImage
    ) {
        $this->request = $request;
        $this->sweetMemoriesImage = $sweetMemoriesImage;
        $this->imageManager = $imageManager;
    }

    /**
     * Create a new sweet configuration for the given event.
     */
    public function create(Events $event): Collection
    {
        $uploadedImages = [];

        try {
            $files = $this->request->file('files');
            foreach ($files as $index => $file) {
                if (! $file->isValid()) {
                    Log::error('Invalid file upload', [
                        'error' => $file->getError(),
                        'errorMessage' => $file->getErrorMessage(),
                    ]);

                    continue;
                }

                $storagePath = "images/event/$event->id/memories-images";
                $path = $file->store($storagePath, self::STORAGE_DISK);

                [$thumbnailPath, $thumbnailName] = $this->generateThumbnail($file, $event);
                $metadata = $this->request->get('metadata');

                Log::info('checking metadata', $metadata);

                $uploadedImage = SweetMemoriesImage::query()->create([
                    'event_id' => $event->id,
                    'image_path' => $path,
                    'image_name' => $file->getClientOriginalName(),
                    'image_original_name' => Arr::get($metadata, "$index.name"),
                    'image_size' => Arr::get($metadata, "$index.size"),
                    'thumbnail_path' => $thumbnailPath,
                    'thumbnail_name' => $thumbnailName,
                ]);

                $uploadedImages[] = $uploadedImage;
            }

            return collect($uploadedImages);

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
