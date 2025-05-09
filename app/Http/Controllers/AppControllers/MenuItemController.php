<?php

namespace App\Http\Controllers\AppControllers;

use App\Models\Events;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class MenuItemController extends Controller
{
    public function store(Request $request, Events $event, Menu $menu): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'itemType' => 'required|string|max:255',
            'dietType' => 'nullable|string|max:100',
            'imagePath' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        
        $item = $menu->menuItems()->create([
            'name' => $data['name'],
            'type' => $data['itemType'],
            'diet_type' => $data['dietType'],
            'image_path' => $data['imagePath'] ?? null,
            'notes' => $data['notes'],
        ]);
        
        return response()->json($item, 201);
    }
    
    public function update(Request $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'dietType' => 'nullable|string|max:100',
            'imagePath' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        
        $menuItem->update([
            'name' => $data['name'],
            'diet_type' => $data['dietType'],
            'image_path' => $data['imagePath'],
            'notes' => $data['notes'],
        ]);
        
        return response()->json($menuItem);
    }
    
    public function destroy(MenuItem $menuItem): JsonResponse
    {
        $menuItem->delete();
        
        return response()->json(['message' => 'Menu item deleted']);
    }
}

