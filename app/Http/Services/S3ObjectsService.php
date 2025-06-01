<?php

namespace App\Http\Services;

use App\Models\EventImage;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class S3ObjectsService
{
    private string $folder;

    /**
     * S3ObjectsService construct
     */
    public function __construct(string $folder)
    {
        $this->folder = $folder;
    }

    /**
     * Getting objects by folder
     */
    public function objectsByFolder(): array
    {
        $s3ClientObject = $this->getS3Instance();

        $result = $this->getListObjects($this->folder/* 'vanessa' */, $s3ClientObject);

        if (isset($result['Contents'])) {
            $tempResult = [];
            foreach ($result['Contents'] as $content) {
                $path = $content['Key'];
                $filename = basename($path);
                $tempResult[$filename][] = $content;
            }

            return ['status' => 200, 'message' => 'Objects loaded successfully', 'result' => $tempResult];
        }

        return ['status' => 404, 'message' => 'Folder not found', 'result' => []];
    }

    /**
     * Retrieves an instance of the S3Client class.
     *
     * @return S3Client The S3Client instance.
     */
    private function getS3Instance(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }

    /**
     * Retrieves a list of objects in a specified folder from an S3 bucket using the provided S3 client instance.
     *
     * @param  string  $folder  The folder path within the S3 bucket.
     * @param  S3Client  $client  The S3Client instance to use for retrieving the list of objects.
     * @return Result The result of the listObjects operation.
     */
    private function getListObjects(string $folder, S3Client $client): Result
    {
        return $client->listObjects([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Prefix' => "event_images/$folder",
        ]);
    }

    /**
     * Downloads a file from S3.
     *
     * @param  string  $key  The key of the file to download.
     * @return array The URL of the downloaded file.
     */
    public function downloadFile(string $key): array
    {
        $fileKey = "event_images/$this->folder/images/$key";

        $s3Client = $this->getS3Instance();

        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $fileKey,
        ]);

        $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');

        $url = (string) $request->getUri();

        return ['fileUrl' => $url];
    }

    /**
     * Delete File.
     */
    public function deleteFile(string $key): bool
    {
        $imageKey = "event_images/$this->folder/images/$key";
        $thumbnailKey = "event_images/$this->folder/thumbnails/$key";

        $s3Client = $this->getS3Instance();
        $s3Client->deleteObject([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $imageKey,
        ]);

        $s3Client->deleteObject([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $thumbnailKey,
        ]);

        $this->deleteInLocalDB($key);

        return true;
    }

    /**
     * Deletes records from the local database where the image_path column contains the given key.
     *
     * @param  string  $key  The key to search for in the image_path column.
     */
    private function deleteInLocalDB(string $key): void
    {
        $eventImages = EventImage::query()
            ->where('image_path', 'LIKE', '%' . $key . '%');

        if ($eventImages->count()) {
            $eventImages->delete();
        }
    }

    public function deleteTheEntireFolder(): bool
    {
        $folderPath = "event_images/$this->folder";

        $files = Storage::disk('s3')->files($folderPath);

        if (count($files)) {
            foreach ($files as $file) {
                Storage::disk('s3')->delete($file);
            }

            $this->deleteFolderInLocal();
        }

        return true;
    }

    /**
     * Deletes a folder in the local storage.
     *
     * This method deletes the folder with the name specified in the `$folder` property of the class. It first queries
     * the `event_images` table to check if there are any records with the same `user_name` as the folder name*/
    private function deleteFolderInLocal(): void
    {
        $eventImages = EventImage::query()
            ->where('user_name', $this->folder);

        if ($eventImages->count()) {
            $eventImages->delete();
        }
    }
}
