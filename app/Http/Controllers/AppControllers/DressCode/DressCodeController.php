<?php

namespace App\Http\Controllers\AppControllers\DressCode;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\DressCode\DressCodeResource;
use App\Http\Services\AppServices\DressCode\DressCodeServices;
use App\Models\DressCode;
use App\Models\Events;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DressCodeController extends Controller
{
    private DressCodeServices $dressCodeServices;

    public function __construct(DressCodeServices $dressCodeServices)
    {
        $this->dressCodeServices = $dressCodeServices;
    }

    /**
     * Fetch the dress code associated with a specific event.
     *
     * @param  Events  $event  The event instance to retrieve the dress code for.
     *
     * @throws \Exception If an exception occurs while retrieving the dress code.
     */
    public function getDressCode(Events $event): DressCodeResource|JsonResponse
    {
        try {
            $dressCode = $this->dressCodeServices->getDressCodeByEvent($event);

            if (! $dressCode) {
                return response()->json([
                    'message' => 'No dress code found for this event.',
                ]);
            }

            return DressCodeResource::make($dressCode);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while retrieving the dress code.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handles the creation of a dress code for an event.
     *
     * This method validates the request data, creates a new dress code associated with the given event,
     * and returns a `DressCodeResource` on success. If the creation fails or an exception occurs, an appropriate
     * JSON response with an error message is returned.
     *
     * @param  Request  $request  The HTTP request containing the dress code data.
     * @return DressCodeResource|JsonResponse The created dress code resource on success or a JSON response in case of failure.
     *
     * @throws Exception If an error occurs during the process.
     */
    public function storeDressCode(Request $request, Events $event): DressCodeResource|JsonResponse
    {
        try {
            $data = $request->validate([
                'dressCodeType' => 'required|string|max:255',
                'description' => 'nullable|string',
                'reservedColors' => 'nullable|string',
                'dressCodeImages' => 'nullable|array',
            ]);

            $dressCode = $this->dressCodeServices->createDressCode($event, $data) ?? null;

            if (! $dressCode) {
                return response()->json([
                    'message' => 'Failed to create dress code.',
                ], 400);
            }

            return DressCodeResource::make($dressCode);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while creating the dress code.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Updates an existing dress code for a specific event.
     *
     * This method validates the incoming request data, updates the provided dress code entity
     * with the new data, and returns a `DressCodeResource` if the update is successful.
     * In cases where the update fails or an exception is caught, an appropriate
     * JSON response with an error message is returned.
     *
     * @param  Request  $request  The HTTP request containing updated dress code details.
     * @param  Events  $event  The event associated with the dress code being updated.
     * @param  DressCode  $dressCode  The existing dress code entity to be updated.
     * @return DressCodeResource|JsonResponse The updated dress code resource upon success or a JSON response on failure.
     *
     * @throws Exception If an error occurs during the update operation.
     */
    public function updateDressCode(Request $request, Events $event, DressCode $dressCode): DressCodeResource|JsonResponse
    {

        try {
            $data = $request->validate([
                'dressCodeType' => 'required|string|max:255',
                'description' => 'nullable|string',
                'reservedColors' => 'nullable|string',
                'dressCodeImages' => 'nullable|array',
                'existingImageIds' => 'nullable|string',
            ]);

            $dressCode = $this->dressCodeServices->updateDressCode($dressCode, $data) ?? null;

            if (! $dressCode) {
                return response()->json([
                    'message' => 'Failed to update dress code.',
                ], 400);
            }

            return DressCodeResource::make($dressCode);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating the dress code.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deletes a dress code associated with a specific event.
     *
     * This method attempts to delete the specified dress code and returns a JSON response indicating success or failure.
     *
     * @param  DressCode  $dressCode  The dress code instance to be deleted.
     * @return DressCodeResource|JsonResponse A JSON response indicating the result of the deletion operation.
     */
    public function destroyDressCode(Events $event, DressCode $dressCode): DressCodeResource|JsonResponse
    {
        try {
            $dressCode = $this->dressCodeServices->deleteDressCode($dressCode);

            if (! $dressCode) {
                return response()->json([
                    'message' => 'No dress code found for this event.',
                ], 404);
            }

            return response()->json([
                'message' => 'Dress code deleted successfully.',
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while deleting the dress code.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function generateImages(Request $request, Events $event): DressCodeResource|JsonResponse
    {
        try {
            $data = $request->validate([
                'dressType' => 'required|string|max:255',
            ]);

            $dressCodeImages = $this->dressCodeServices->generateDressCodeAIImages($event, $data) ?? null;

            if (! $dressCodeImages) {
                return response()->json([
                    'message' => 'Failed to generate dress code images.',
                ], 400);
            }

            return response()->json([
                'message' => 'Dress code images generated successfully.',
                'dressCode' => $dressCodeImages,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'An error occurred while generating dress code images.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
