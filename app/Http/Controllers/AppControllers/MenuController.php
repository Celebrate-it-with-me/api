<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * Display the menu for the given event.
     *
     * @param Events $event
     * @return JsonResponse
     */
    public function show(Events $event): JsonResponse
    {
        $menu = $event->menu()->with('items')->first();
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
        ]);
        
        $menu = $event->menu()->create([
            'title' => $data['title'],
            'description' => $data['description'],
            'allow_multiple_choices' => $data['allowMultipleChoices'],
            'allow_custom_requests' => $data['allowCustomRequests'],
        ]);
        
        return response()->json($menu, 201);
    }
    
    /**
     * Update the menu for the given event.
     *
     * @param Request $request
     * @param Events $event
     * @return JsonResponse
     */
    public function update(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'allowMultipleChoices' => 'boolean',
            'allowCustomRequests' => 'boolean',
        ]);
        
        $menu = $event->menu;
        
        if ($menu) {
            $menu->update([
                'title' => $data['title'],
                'description' => $data['description'],
                'allow_multiple_choices' => $data['allowMultipleChoices'],
                'allow_custom_requests' => $data['allowCustomRequests'],
            ]);
            
            return response()->json($menu);
        }
        
        return response()->json(['message' => 'Menu not found'], 404);
    }
    
    /**
     * Delete the menu for the given event.
     *
     * @param Events $event
     * @return JsonResponse
     */
    public function destroy(Events $event): JsonResponse
    {
        $menu = $event->menu;
        
        if ($menu) {
            $menu->delete();
            return response()->json(['message' => 'Menu deleted successfully']);
        }
        
        return response()->json(['message' => 'Menu not found'], 404);
    }
}
