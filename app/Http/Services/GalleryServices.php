<?php

namespace App\Http\Services;

use App\Models\EventImage;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class GalleryServices
{
    private Request $request;
    private String $disk;

    private ImageManager $imageManager;

    public function __construct(Request $request, ImageManager $imageManager)
    {
        $this->imageManager = $imageManager;
        $this->request = $request;
        $this->disk = (app()->environment('local'))
            ? 'local'
            : 's3';
    }

    /**
     * Uploads images and saves them to a specified folder.
     *
     * @return void
     */
    public function upload(string $userName): void
    {
        $folderName = $this->getBasePath($userName);
        $thumbnailFolderName = $this->getThumbnailBasePath($userName);
        $images = $this->request->file('images');

        if ($images) {
            foreach($images as $image) {

                $path = Storage::disk($this->disk)
                    ->putFile($folderName, $image);

                $imageContent = Storage::disk($this->disk)->get($path);

                $imgThumbnail = $this->imageManager->read($imageContent)
                    ->resize(150,150);

                $thumbnailPath =  $thumbnailFolderName . '/' . $image->hashName();
                Storage::disk($this->disk)
                    ->put($thumbnailPath, (string) $imgThumbnail->encode());

                EventImage::query()->create([
                    'user_name' => $userName,
                    'image_path' => $path,
                    'thumbnail_path' => $thumbnailPath
                ]);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function showImages(string $userName): array|Collection
    {
        $images = EventImage::query()
            ->where('user_name', $userName)
            ->get();

        if (!$images) {
            throw new Exception('There is no images associated to this user!');
        }

        return $images;
    }

    private function getThumbnailBasePath(string $userName): string
    {
        if ($this->disk === 'local') {
            return "public/event_images/$userName/thumbnails";
        }

        return "event_images/$userName/thumbnails";
    }

    /**
     * Retrieves the base path for event images.
     *
     * @return string The base path for event images.
     */
    private function getBasePath(string $username): string
    {
        if($this->disk === 'local') {
            return "public/event_images/$username/images";
        }

        return "event_images/$username/images";
    }

}
