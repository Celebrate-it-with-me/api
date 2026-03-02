<?php
// app/Http/Controllers/ThemeAssetController.php

namespace App\Http\Controllers\AppControllers\Theme;

use App\Http\Controllers\Controller;
use App\Http\Requests\Theme\UploadAssetRequest;
use App\Http\Services\AppServices\Theme\AssetUploadService;
use App\Models\Events;
use App\Models\Theme\ThemeAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeAssetController extends Controller
{
    public function __construct(
        private AssetUploadService $assetUploadService
    ) {}

    /**
     *
     * @param Events $event
     * @return JsonResponse
     */
    public function index(Events $event): JsonResponse
    {
        $theme = $event->theme;

        if (!$theme) {
            return response()->json([
                'success' => true,
                'data' => [
                    'assets' => []
                ]
            ]);
        }

        $assets = $theme->assets()
            ->orderBy('section')
            ->orderBy('asset_type')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'assets' => $assets,
                'grouped_by_section' => $assets->groupBy('section')
            ]
        ]);
    }

    /**
     * Subir un nuevo asset
     */
    public function store(Events $event, UploadAssetRequest $request): JsonResponse
    {
        try {
            $asset = $this->assetUploadService->uploadAsset(
                $event,
                $request->file('file'),
                $request->input('section'),
                $request->input('asset_type'),
                $request->only(['alt_text', 'sort_order'])
            );

            return response()->json([
                'success' => true,
                'message' => 'Asset uploaded successfully',
                'data' => [
                    'asset' => $asset
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar metadata de un asset
     */
    public function update(Events $event, ThemeAsset $asset, Request $request): JsonResponse
    {
        // Verificar que el asset pertenece al evento
        if ($asset->eventTheme->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Asset not found for this event'
            ], 404);
        }

        $request->validate([
            'alt_text' => 'sometimes|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean'
        ]);

        try {
            $asset->update($request->only(['alt_text', 'sort_order', 'is_active']));

            return response()->json([
                'success' => true,
                'message' => 'Asset updated successfully',
                'data' => [
                    'asset' => $asset->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un asset
     */
    public function destroy(Events $event, ThemeAsset $asset): JsonResponse
    {
        // Verificar que el asset pertenece al evento
        if ($asset->eventTheme->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Asset not found for this event'
            ], 404);
        }

        try {
            $this->assetUploadService->deleteAsset($asset);

            return response()->json([
                'success' => true,
                'message' => 'Asset deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reemplazar un asset existente
     */
    public function replace(Events $event, ThemeAsset $asset, UploadAssetRequest $request): JsonResponse
    {
        // Verificar que el asset pertenece al evento
        if ($asset->eventTheme->event_id !== $event->id) {
            return response()->json([
                'success' => false,
                'message' => 'Asset not found for this event'
            ], 404);
        }

        try {
            $newAsset = $this->assetUploadService->replaceAsset(
                $asset,
                $request->file('file'),
                $request->only(['alt_text'])
            );

            return response()->json([
                'success' => true,
                'message' => 'Asset replaced successfully',
                'data' => [
                    'asset' => $newAsset
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to replace asset: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener assets por sección
     */
    public function bySection(Events $event, string $section): JsonResponse
    {
        $theme = $event->theme;

        if (!$theme) {
            return response()->json([
                'success' => true,
                'data' => [
                    'section' => $section,
                    'assets' => []
                ]
            ]);
        }

        $assets = $theme->getAssetsBySection($section);

        return response()->json([
            'success' => true,
            'data' => [
                'section' => $section,
                'assets' => $assets
            ]
        ]);
    }

    /**
     * Reordenar assets dentro de una sección
     */
    public function reorder(Events $event, Request $request): JsonResponse
    {
        $request->validate([
            'assets' => 'required|array',
            'assets.*.id' => 'required|exists:theme_assets,id',
            'assets.*.sort_order' => 'required|integer|min:0'
        ]);

        try {
            foreach ($request->input('assets') as $assetData) {
                $asset = ThemeAsset::find($assetData['id']);

                // Verificar que pertenece al evento
                if ($asset->eventTheme->event_id === $event->id) {
                    $asset->update(['sort_order' => $assetData['sort_order']]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Assets reordered successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder assets: ' . $e->getMessage()
            ], 500);
        }
    }
}
