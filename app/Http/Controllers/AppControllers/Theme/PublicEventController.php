<?php

namespace App\Http\Controllers\AppControllers\Theme;

use App\Http\Controllers\Controller;
use App\Http\Requests\Theme\PublicRsvpRequest;
use App\Http\Services\AppServices\Theme\PublicEventService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class PublicEventController extends Controller
{
    public function __construct(
        private PublicEventService $publicEventService
    ) {}

    /**
     * Retrieve event data for a guest.
     *
     * @param int $eventId
     * @param string $guestToken
     * @return JsonResponse
     */
    public function getEventData(int $eventId, string $guestToken): JsonResponse
    {
        try {
            $data = $this->publicEventService->getEventDataForGuest($eventId, $guestToken);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event or invitation not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to load event data'
            ], 500);
        }
    }

    /**
     * Process RSVP submission for a guest.
     *
     * @param int $eventId
     * @param string $guestToken
     * @return JsonResponse
     */
    public function getEventTheme(int $eventId, string $guestToken): JsonResponse
    {
        try {
            $data = $this->publicEventService->getEventThemeForGuest($eventId, $guestToken);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event or invitation not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to load theme'
            ], 500);
        }
    }

    /**
     * Process RSVP submission for a guest.
     *
     * @param int $eventId
     * @param string $guestToken
     * @param PublicRsvpRequest $request
     * @return JsonResponse
     */
    public function submitRsvp(int $eventId, string $guestToken, PublicRsvpRequest $request): JsonResponse
    {
        try {
            $guest = $this->publicEventService->submitGuestRsvp(
                $eventId,
                $guestToken,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'RSVP submitted successfully',
                'data' => [
                    'guest' => $guest
                ]
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event or invitation not found'
            ], 404);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit RSVP'
            ], 500);
        }
    }

    /**
     * Retrieve a specific section of the event for a guest.
     *
     * @param int $eventId The ID of the event.
     * @param string $guestToken The token identifying the guest.
     * @param string $section The section of the event to retrieve.
     * @return JsonResponse A JSON response containing the section data or an error message.
     */
    public function getEventSection(int $eventId, string $guestToken, string $section): JsonResponse
    {
        try {
            $data = $this->publicEventService->getEventSectionForGuest($eventId, $guestToken, $section);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event or invitation not found'
            ], 404);

        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to load section data'
            ], 500);
        }
    }
}
