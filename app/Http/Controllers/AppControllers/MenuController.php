<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\GuestMenuConfirmationResource;
use App\Models\Events;
use App\Models\Guest;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class MenuController extends Controller
{
    /**
     * Getting menu from this event.
     * @param Request $request
     * @param Events $event
     * @return JsonResponse
     */
    public function index(Request $request, Events $event): JsonResponse
    {
        try {
            $menus = $event->menus()->with('menuItems')->get();
            if (!$menus)  {
                return response()->json(['message' => 'There is no menus for this event.'], 404);
            }
            Log::info('MenuController@index', [
                'event_id' => $event->id,
                'menus' => $menus,
            ]);
            return response()->json($menus);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
    /**
     *
     * @param Request $request
     * @param Events $event
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function getGuestsMenu(Request $request, Events $event): JsonResponse | AnonymousResourceCollection
    {
        try {
            $guests = Guest::query()
                ->with(['selectedMenuItems'])
                ->where('event_id', $event->id)
                ->paginate($request->get('per_page', 10));
            
            if (!$guests->count()) {
                return response()->json(['message' => 'There is no guests for this event.'], 404);
            }
            
            return GuestMenuConfirmationResource::collection($guests);
        } catch (\Throwable $e)    {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
    
    /**
     * Display the menu for the given event.
     *
     * @param Events $event
     * @return JsonResponse
     */
    public function show(Events $event, Menu $menu): JsonResponse
    {
        $menu = $menu->load('menuItems');
        return response()->json($menu);
    }
    
    /**
     * Store a new menu for the given event.
     *
     * @param Request $request
     * @param Events $event
     * @return JsonResponse
     */
    public function store(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'allowMultipleChoices' => 'boolean',
            'allowCustomRequests' => 'boolean',
            'isDefault' => 'boolean',
        ]);
        
        if ($data['isDefault']) {
            $event->menus()->update([
                'is_default' => false,
            ]);
        }
        
        $menu = $event->menus()->create([
            'title' => $data['title'],
            'description' => $data['description'],
            'allow_multiple_choices' => $data['allowMultipleChoices'] ?? false,
            'allow_custom_requests' => $data['allowCustomRequests'] ?? false,
            'is_default' => $data['isDefault'] ?? $this->getIsDefaultMenu($event),
        ]);
        
        return response()->json($menu, 201);
    }
    
    /**
     * Check if the menu is default.
     * @param Events $event
     * @return bool
     */
    private function getIsDefaultMenu(Events $event): bool
    {
        $menus = $event->menus()->where('is_default',  true)->get();
        if ($menus->isEmpty()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Update the menu for the given event.
     *
     * @param Request $request
     * @param Events $event
     * @return JsonResponse
     */
    public function update(Request $request, Events $event, Menu $menu): JsonResponse
    {
        try {
            $data = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'allowMultipleChoices' => 'boolean',
                'allowCustomRequests' => 'boolean',
            ]);
            
            $menu->update([
                'title' => $data['title'],
                'description' => $data['description'],
                'allow_multiple_choices' => $data['allowMultipleChoices'] ?? false,
                'allow_custom_requests' => $data['allowCustomRequests'] ?? false,
            ]);
            
            return response()->json($menu);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Delete the menu for the given event.
     *
     * @param Events $event
     * @return JsonResponse
     */
    public function destroy(Events $event, Menu $menu): JsonResponse
    {
        try {
            $menu->delete();
            return response()->json(['message' => 'Menu deleted successfully']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Menu not found'], 404);
        }
    }
}
