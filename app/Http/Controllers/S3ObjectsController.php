<?php

namespace App\Http\Controllers;

use App\Http\Services\S3ObjectsService;
use App\Models\EventImage;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class S3ObjectsController extends Controller
{
    /**
     * Get objects by folder.
     * @param string $folder
     * @return JsonResponse
     */
    public function getObjectsByFolder(string $folder): JsonResponse
    {
        try {
            $folderObjects = (new S3ObjectsService($folder))->objectsByFolder();

            if (!isset($folderObjects['status'])) {
                return response()->json(['message' => "Invalid folder $folder."], 400);
            }

            return response()->json([
                    'result' => $folderObjects['result'],
                    'message' => $folderObjects['message']
                ],
                $folderObjects['status']
            );

        } catch (Exception $e) {
            Log::error('Ops something went wrong', [$e->getMessage()]);
            return response()->json(['message' => 'Ops something went wrong!']);
        }
    }

    /**
     * Retrieve all folders
     *
     * @return JsonResponse
     */
    public function getFolders(): JsonResponse
    {
        try {
           $usernameFolders = EventImage::query()
               ->groupBy('user_name')
               ->get('user_name')
               ->pluck('user_name')
               ->toArray();

            $defaultFolders = ['Caitlin', 'Vanessa'];

           $currentFolders = [
               ...$defaultFolders,
               ...$usernameFolders
            ];

           return response()->json(['folders' => $currentFolders]);

        } catch (Exception $e) {
            Log::error('Ops something went wrong', [$e->getMessage()]);
            return response()->json(['message' => 'Ops something went wrong!']);
        }
    }

    /**
     * Download a file from a specific folder
     *
     * @param string $folder The folder name
     * @param string $key The file key
     * @return JsonResponse
     */
    public function downloadFile(string $folder, string $key): JsonResponse
    {
        try {
            $downloadFile = (new S3ObjectsService($folder))->downloadFile($key);

            return response()->json($downloadFile);

        } catch (Exception $e) {
            Log::error('Ops something went wrong', [$e->getMessage()]);
            return response()->json(['message' => 'Ops something went wrong!']);
        }
    }

    /**
     * Delete a file from the specified folder
     *
     * @param string $folder The folder name
     */
    public function deleteFile(string $folder, string $key): JsonResponse
    {
        try {
            (new S3ObjectsService($folder))->deleteFile($key);

            return response()->json([true]);
        } catch (Exception $e) {
            Log::error('Ops something went wrong', [$e->getMessage()]);
            return response()->json(['message' => 'Ops something went wrong!']);
        }
    }

    /**
     * Delete a folder
     *
     * @param string $folder The name of the folder to delete
     * @return JsonResponse
     */
    public function deleteFolder(string $folder): JsonResponse
    {
        try {
            (new S3ObjectsService($folder))->deleteTheEntireFolder();

            return response()->json([true]);
        } catch (Exception $e) {
            Log::error('Ops something went wrong', [$e->getMessage()]);
            return response()->json(['message' => 'Ops something went wrong!']);
        }
    }

}
