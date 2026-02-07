<?php

namespace App\Http\Controllers\AppControllers\Theme;

use App\Http\Controllers\Controller;
use App\Http\Requests\Theme\UpdateThemeRequest;
use App\Http\Services\AppServices\Theme\ThemeService;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EventThemeController extends Controller
{
    public function __construct(
        private readonly ThemeService $themeService
    ) {}

    /**
     * Handles the retrieval of the theme based on the given event, creates a theme if it does not already exist,
     * and returns a JSON response with the theme data and its resolved configuration.
     *
     * @param Events $event The event instance used to retrieve or create a theme.
     * @return JsonResponse A JSON response containing the success status, theme data,
     *                                       and its resolved configuration.
     */
    public function show(Events $event)
    {
        $theme = $this->themeService->getOrCreateTheme($event);

        return response()->json([
           'success' => true,
           'data' => [
               'theme' => $theme,
               'resolved_config' => $this->themeService->getResolvedConfig($theme)
           ]
        ]);
    }

    /**
     * Updates the theme configuration associated with the given event using the validated request data,
     * and returns a JSON response indicating the success or failure of the operation.
     *
     * @param Events $event The event instance for which the theme configuration is to be updated.
     * @param UpdateThemeRequest $request The request instance containing the validated theme configuration data.
     * @return JsonResponse A JSON response indicating the success or failure of the update operation,
     *                      along with the updated theme data and its resolved configuration on success.
     */
    public function update(Events $event, UpdateThemeRequest $request): JsonResponse
    {
        try {
            $theme = $this->themeService->updateThemeConfig(
                $event,
                $request->validated()['theme_config']
            );

            return response()->json([
                'success' => true,
                'message' => 'Theme updated successfully',
                'data' => [
                    'theme' => $theme,
                    'resolved_config' => $this->themeService->getResolvedConfig($theme)
                ]
            ]);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update theme: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Resets the theme to its default configuration.
     *
     * @param Events $event The event triggering the reset operation.
     * @return JsonResponse A JSON response containing the result of the reset operation.
     */
    public function reset(Events $event): JsonResponse
    {
        try {
            $theme = $this->themeService->resetToDefault($event);

            return response()->json([
                'success' => true,
                'message' => 'Theme reset to default',
                'data' => [
                    'theme' => $theme,
                    'resolved_config' => $this->themeService->getResolvedConfig($theme)
                ]
            ]);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset theme: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Deletes a custom theme and reverts to system default settings.
     *
     * @param Events $event The event associated with the deletion operation.
     * @return JsonResponse A JSON response indicating the result of the deletion process.
     */
    public function destroy(Events $event): JsonResponse
    {
        try {
            $this->themeService->deleteTheme($event);

            return response()->json([
                'success' => true,
                'message' => 'Custom theme deleted, reverted to system defaults'
            ]);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete theme: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Generates a preview of the theme configuration based on the provided input.
     *
     * @param Events $event The event triggering the preview operation.
     * @param Request $request The HTTP request containing the theme configuration input.
     * @return JsonResponse A JSON response containing the resolved preview configuration.
     */
    public function preview(Events $event, Request $request): JsonResponse
    {
        $previewConfig = $request->input('theme_config', []);

        $resolvedConfig = $this->themeService->resolveConfig($previewConfig);

        return response()->json([
            'success' => true,
            'data' => [
                'preview_config' => $resolvedConfig
            ]
        ]);
    }
}


