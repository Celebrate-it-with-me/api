<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\GuestMenuConfirmationResource;
use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\Guest;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        // No menus yet is a normal answer: an empty list.
        return response()->json($event->menus()->with('menuItems')->get());
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
            
            // No guests yet is a normal answer: an empty page.
            return GuestMenuConfirmationResource::collection($guests);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The guest menu selections could not be loaded.'], 500);
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
        $this->acceptBothSpellings($request);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'allowMultipleChoices' => 'boolean',
            'allowCustomRequests' => 'boolean',
            'isDefault' => 'boolean',
        ]);
        
        if ($data['isDefault'] ?? false) {
            $event->menus()->update([
                'is_default' => false,
            ]);
        }
        
        $menu = $event->menus()->create([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'allow_multiple_choices' => $data['allowMultipleChoices'] ?? false,
            'allow_custom_request' => $data['allowCustomRequests'] ?? false,
            'is_default' => $data['isDefault'] ?? $this->getIsDefaultMenu($event),
        ]);
        
        EventActivityLogger::log(
            $event->id,
            'menu_created',
            auth()->user(),
            $menu,
            [
                'title' => $menu->title,
                'description' => $menu->description,
                'allow_multiple_choices' => $menu->allow_multiple_choices,
                'allow_custom_request' => $menu->allow_custom_request,
                'is_default' => $menu->is_default,
            ]
        );
        
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
    
    public function update(Request $request, Events $event, Menu $menu): JsonResponse
    {
        $this->acceptBothSpellings($request);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'allowMultipleChoices' => 'boolean',
            'allowCustomRequests' => 'boolean',
            'isDefault' => 'boolean',
        ]);

        // Only one menu is the main one: making this one main takes it from the others.
        if ($data['isDefault'] ?? false) {
            $event->menus()->where('id', '!=', $menu->id)->update(['is_default' => false]);
        }

        $menu->update([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'allow_multiple_choices' => $data['allowMultipleChoices'] ?? false,
            'allow_custom_request' => $data['allowCustomRequests'] ?? false,
            'is_default' => $data['isDefault'] ?? $menu->is_default,
        ]);

        EventActivityLogger::log(
            $event->id,
            'menu_updated',
            auth()->user(),
            $menu,
            [
                'title' => $menu->title,
                'description' => $menu->description,
                'allow_multiple_choices' => $menu->allow_multiple_choices,
                'allow_custom_request' => $menu->allow_custom_request,
                'is_default' => $menu->is_default,
            ]
        );

        return response()->json($menu);
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
            
            EventActivityLogger::log(
                $event->id,
                'menu_deleted',
                auth()->user(),
                $menu,
                [
                    'title' => $menu->title,
                    'description' => $menu->description,
                    'allow_multiple_choices' => $menu->allow_multiple_choices,
                    'allow_custom_request' => $menu->allow_custom_request,
                ]
            );
            
            return response()->json(['message' => 'Menu deleted successfully']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The menu could not be deleted.'], 500);
        }
    }

    /** The app has sent both spellings of this switch over time; the column is `allow_custom_request`. */
    private function acceptBothSpellings(Request $request): void
    {
        if (! $request->has('allowCustomRequests') && $request->has('allowCustomRequest')) {
            $request->merge(['allowCustomRequests' => $request->input('allowCustomRequest')]);
        }
    }
}
