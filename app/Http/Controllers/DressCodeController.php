<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDressCodeRequest;
use App\Http\Requests\UpdateDressCodeRequest;
use App\Http\Services\AppServices\DressCode\DressCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DressCodeController extends Controller
{
    protected DressCodeService $dressCodeService;

    public function __construct(DressCodeService $dressCodeService)
    {
        $this->dressCodeService = $dressCodeService;
    }

    /**
     * Retrieve dress code for specific event.
     */
    public function index(int $eventId): JsonResponse
    {
        $data = $this->dressCodeService->getDressCode($eventId);

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * Create new dress code for event.
     */
    public function store(StoreDressCodeRequest $request, int $eventId): JsonResponse
    {
        try {
            $this->dressCodeService->createDressCode(
                $eventId,
                $request->validated(),
                $request->file('dressCodeImages')
            );

            return response()->json(['message' => 'Dress code created successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * Update existing dress code.
     */
    public function update(UpdateDressCodeRequest $request, int $eventId, int $dressCodeId): JsonResponse
    {
        try {
            $this->dressCodeService->updateDressCode(
                $eventId,
                $dressCodeId,
                $request->validated(),
                $request->file('dressCodeImages')
            );

            return response()->json(['message' => 'Dress code updated successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }
    
    /**
     * Delete a dress code associated with a specific event.
     *
     * @param int $event
     * @param int $dressCodeId
     * @return JsonResponse
     */
    public function destroy(int $event, int $dressCodeId)
    {
        try {
            $this->dressCodeService->deleteDressCode($event, $dressCodeId);
            
            return response()->json(['message' => 'Dress code deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * Generate AI images for dress code (Optional placeholder).
     */
    public function generateImages(Request $request, int $eventId): JsonResponse
    {
        $request->validate(['dressType' => 'required|string']);

        $mockImages = $this->dressCodeService->generateMockImages();

        return response()->json($mockImages);
    }
}
