<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\Logger\EventActivityLogger;
use App\Models\Events;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
            'notes' => $data['notes'] ?? '',
        ]);

        EventActivityLogger::log(
            $event->id,
            'menu_item_created',
            Auth::user(),
            $item,
            [
                'name' => $item->name,
                'type' => $item->type,
                'diet_type' => $item->diet_type,
                'image_path' => $item->image_path,
                'notes' => $item->notes,
            ]
        );

        return response()->json($item, 201);
    }

    public function update(Request $request, Events $event, MenuItem $menuItem): JsonResponse
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

        EventActivityLogger::log(
            $event->id,
            'menu_item_updated',
            Auth::user(),
            $menuItem,
            [
                'name' => $menuItem->name,
                'type' => $menuItem->type,
                'diet_type' => $menuItem->diet_type,
                'image_path' => $menuItem->image_path,
                'notes' => $menuItem->notes,
            ]
        );

        return response()->json($menuItem);
    }

    public function destroy(Events $event, Menu $menu, MenuItem $menuItem): JsonResponse
    {
        $menuItem->delete();

        EventActivityLogger::log(
            $event->id,
            'menu_item_deleted',
            Auth::user(),
            $menuItem,
            [
                'name' => $menuItem->name,
                'type' => $menuItem->type,
                'diet_type' => $menuItem->diet_type,
                'image_path' => $menuItem->image_path,
                'notes' => $menuItem->notes,
            ]
        );

        return response()->json(['message' => 'Menu item deleted']);
    }
}
