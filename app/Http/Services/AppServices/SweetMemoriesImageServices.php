<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SuggestedMusicConfig;
use App\Models\SweetMemoriesConfig;
use App\Models\SweetMemoriesImage;
use Illuminate\Http\Request;
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
     * @return array
     */
    public function create(Events $event): array
    {
        $uploadedImages = [];
        $files = $this->request->file('files');
        
        if (is_array($files)) {
            foreach ($files as $file) {
                $storagePath = "images/event/$event->id/memories-images";
                $path = $file->store($storagePath, self::STORAGE_DISK);
                [$thumbnailPath, $thumbnailName] = $this->generateThumbnail($file, $event);
                
                $uploadedImage = SweetMemoriesImage::query()->create([
                    'event_id' => $event->id,
                    'image_path' => $path,
                    'image_name' => $file->getClientOriginalName(),
                    'thumbnail_path' => $thumbnailPath,
                    'thumbnail_name' => $thumbnailName,
                ]);
                
                $uploadedImages[] = $uploadedImage;
            }
        }
        
        return $uploadedImages;
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
        $imageContent = Storage::disk(self::STORAGE_DISK)->get($file);
        
        $imageThumbnail = $this->imageManager->read($imageContent)
                            ->resize(150, 150);
        
        $thumbnailName = 'thumbnail_' . $file->getClientOriginalName();
        $thumbnailPath = "images/event/$event->id/memories-thumbnails/$thumbnailName";
        
        Storage::disk(self::STORAGE_DISK)->put($thumbnailPath,(string) $imageThumbnail->encode());
        
        return [$thumbnailPath, $thumbnailName];
    }
    
}
