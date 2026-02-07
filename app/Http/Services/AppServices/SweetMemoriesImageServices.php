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
     * @param Request $request
     * @param ImageManager $imageManager
     * @param SweetMemoriesImage $sweetMemoriesImage
     */
    public function __construct(
        Request $request,
        ImageManager $imageManager,
        SweetMemoriesImage $sweetMemoriesImage
    )
    {
        $this->request = $request;
        $this->sweetMemoriesImage = $sweetMemoriesImage;
        $this->imageManager = $imageManager;
    }


    /**
     * Create a new sweet configuration for the given event.
     *
     * @param Events $event
     * @return Collection
     */
    public function create(Events $event): Collection
    {
        $uploadedImages = [];

        try {
            $files = $this->request->file('files');
            foreach ($files as $index => $file) {
                if (!$file->isValid()) {
                    Log::error('Invalid file upload', [
                        'error' => $file->getError(),
                        'errorMessage' => $file->getErrorMessage()
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
                    'title' => $this->request->get('title'),
                    'description' => $this->request->get('description'),
                    'year' => $this->request->get('year'),
                    'visible' => $this->request->get('visible', true),
                    'order' => $this->request->get('order', 0),
                ]);

                $uploadedImages[] = $uploadedImage;
            }

            return collect($uploadedImages);

        } catch (Exception $e) {
            Log::error('File upload error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generate a thumbnail for the given file and event.
     *
     * @param $file
     * @param Events $event
     * @return array
     */
    private function generateThumbnail($file, $event): array
    {
        $imageThumbnail = $this->imageManager->read($file->getPathname())
                            ->resize(150, 150);

        $thumbnailName = 'thumbnail_' . $file->getClientOriginalName();
        $thumbnailPath = "images/event/$event->id/memories-thumbnails/$thumbnailName";

        Storage::disk(self::STORAGE_DISK)->put($thumbnailPath,(string) $imageThumbnail->encode());

        return [$thumbnailPath, $thumbnailName];
    }

    /**
     * Deletes a sweet memories image and its associated files from storage.
     *
     * @param Events $event
     * @param SweetMemoriesImage $sweetMemoriesImage
     * @return array
     */
    public function destroy(Events $event, SweetMemoriesImage $sweetMemoriesImage): array
    {
        try {
            $imagePath = $sweetMemoriesImage->image_path;
            $thumbnailPath = $sweetMemoriesImage->thumbnail_path;

            Storage::disk(self::STORAGE_DISK)->delete($imagePath);
            Storage::disk(self::STORAGE_DISK)->delete($thumbnailPath);

            $sweetMemoriesImage->delete();

            return [ true, 'Sweet memories image deleted successfully', 200 ];
        } catch (Exception $e) {
            Log::error('Error deleting sweet memories image', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [ false, 'Error deleting sweet memories image', 500];
        }
    }

    /**
     * Updates the sweet memories image.
     *
     * @param Request $request
     * @param SweetMemoriesImage $sweetMemoriesImage
     * @return array
     */
    public function update(Request $request, SweetMemoriesImage $sweetMemoriesImage): array
    {
        try {
            $data = $request->only(['title', 'description', 'year', 'visible', 'order']);

            // Si hay un nuevo archivo, actualizar la imagen
            if ($request->hasFile('files')) {
                // Borrar archivos viejos
                Storage::disk(self::STORAGE_DISK)->delete($sweetMemoriesImage->image_path);
                Storage::disk(self::STORAGE_DISK)->delete($sweetMemoriesImage->thumbnail_path);

                $file = $request->file('files')[0];
                $storagePath = "images/event/{$sweetMemoriesImage->event_id}/memories-images";
                $path = $file->store($storagePath, self::STORAGE_DISK);

                [$thumbnailPath, $thumbnailName] = $this->generateThumbnail($file, $sweetMemoriesImage->event);
                $metadata = $request->get('metadata');

                $data['image_path'] = $path;
                $data['image_name'] = $file->getClientOriginalName();
                $data['image_original_name'] = Arr::get($metadata, "0.name", $file->getClientOriginalName());
                $data['image_size'] = Arr::get($metadata, "0.size", $file->getSize());
                $data['thumbnail_path'] = $thumbnailPath;
                $data['thumbnail_name'] = $thumbnailName;
            }

            $sweetMemoriesImage->update($data);

            return [ true, 'Sweet memories image updated successfully', 200 ];
        } catch (Exception $e) {
            Log::error('Error updating sweet memories image', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [ false, 'Error updating sweet memories image', 500];
        }
    }

    /**
     * Updates the name of the sweet memories image.
     *
     * @param Request $request The HTTP request instance containing the new name.
     * @param SweetMemoriesImage $sweetMemoriesImage The SweetMemoriesImage model instance to be updated.
     * @return array Returns an array with a success status (true/false) and an HTTP status code.
     */
    public function updateName(Request $request, SweetMemoriesImage $sweetMemoriesImage): array
    {
        try {
            $sweetMemoriesImage->update([
                'image_original_name' => $request->get('name')
            ]);

            return [ true, 200 ];
        } catch (Exception $e) {
            Log::error('Error updating sweet memories image name', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [ false, 500];
        }
    }

}
