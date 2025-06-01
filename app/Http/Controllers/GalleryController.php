<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryImageResource;
use App\Http\Services\GalleryServices;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use PHPUnit\TextUI\CliArguments\Exception;

class GalleryController extends Controller
{
    public function __construct() {}

    /**
     * Uploads images to the gallery.
     *
     * @param  Request  $request  The request containing the images to be uploaded.
     * @return JsonResponse The JSON response indicating the success or failure of the image upload.
     */
    public function uploadImages(Request $request, string $userName): JsonResponse
    {
        try {
            app()->make(GalleryServices::class)->upload($userName);

            return response()->json(['message' => 'Images uploaded successfully'], 200);
        } catch (Exception|BindingResolutionException $e) {
            return response()->json(['message' => 'Ups something happens ' . $e->getMessage()], 500);
        }
    }

    /**
     * Retrieves the images associated with the given user.
     *
     * @param  string  $userName  The username of the user whose images to retrieve.
     * @return AnonymousResourceCollection|JsonResponse The collection of gallery images or a JSON response with an error message.
     *
     * @throws Exception If an error occurs while retrieving the images.
     * @throws BindingResolutionException If a binding resolution error occurs.
     */
    public function showImages(string $userName): AnonymousResourceCollection|JsonResponse
    {
        try {
            return GalleryImageResource::collection(app()->make(GalleryServices::class)->showImages($userName));
        } catch (Exception|BindingResolutionException $e) {
            return response()->json(['message' => 'Ups something happens ' . $e->getMessage()], 500);
        }
    }
}
