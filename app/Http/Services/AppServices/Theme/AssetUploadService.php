<?php

namespace App\Http\Services\AppServices\Theme;

use App\Models\Events;
use App\Models\ThemeAsset;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AssetUploadService
{
    public function __construct(
        private ThemeService $themeService
    ) {}

    /**
     * @param Events $event
     * @param UploadedFile $file
     * @param string $section
     * @param string $assetType
     * @param array $metadata
     * @return ThemeAsset
     * @throws Throwable
     */
    public function uploadAsset(
        Events $event,
        UploadedFile $file,
        string $section,
        string $assetType,
        array $metadata = []
    ): ThemeAsset {
        return DB::transaction(function () use ($event, $file, $section, $assetType, $metadata) {
            $theme = $this->themeService->getOrCreateTheme($event);
            $path = $this->generateAssetPath($event, $section, $assetType, $file);

            $processedFile = $this->processFileIfNeeded($file, $assetType);

            $storedPath = $processedFile
                ? Storage::putFileAs(dirname($path), $processedFile, basename($path))
                : Storage::putFileAs(dirname($path), $file, basename($path));

            $asset = ThemeAsset::query()->create([
                'event_theme_id' => $theme->id,
                'section' => $section,
                'asset_type' => $assetType,
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'alt_text' => $metadata['alt_text'] ?? null,
                'sort_order' => $metadata['sort_order'] ?? 0,
                'is_active' => true
            ]);

            $this->deactivateOtherAssetsIfUnique($theme, $section, $assetType, $asset);

            return $asset;
        });
    }

    /**
     * @param ThemeAsset $existingAsset
     * @param UploadedFile $newFile
     * @param array $metadata
     * @return ThemeAsset
     * @throws Throwable
     */
    public function replaceAsset(
        ThemeAsset $existingAsset,
        UploadedFile $newFile,
        array $metadata = []
    ): ThemeAsset {
        return DB::transaction(function () use ($existingAsset, $newFile, $metadata) {
            if (Storage::exists($existingAsset->file_path)) {
                Storage::delete($existingAsset->file_path);
            }

            $path = $this->generateAssetPath(
                $existingAsset->eventTheme->event,
                $existingAsset->section,
                $existingAsset->asset_type,
                $newFile
            );

            $processedFile = $this->processFileIfNeeded($newFile, $existingAsset->asset_type);

            $storedPath = $processedFile
                ? Storage::putFileAs(dirname($path), $processedFile, basename($path))
                : Storage::putFileAs(dirname($path), $newFile, basename($path));

            $existingAsset->update([
                'file_path' => $storedPath,
                'file_name' => $newFile->getClientOriginalName(),
                'file_size' => $newFile->getSize(),
                'mime_type' => $newFile->getMimeType(),
                'alt_text' => $metadata['alt_text'] ?? $existingAsset->alt_text,
            ]);

            return $existingAsset->fresh();
        });
    }

    /**
     * @param ThemeAsset $asset
     * @return void
     * @throws Throwable
     */
    public function deleteAsset(ThemeAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            if (Storage::exists($asset->file_path)) {
                Storage::delete($asset->file_path);
            }

            $asset->delete();
        });
    }

    /**
     * @param Events $event
     * @param string $section
     * @param string $assetType
     * @param UploadedFile $file
     * @return string
     */
    private function generateAssetPath(Events $event, string $section, string $assetType, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::slug($assetType . '-' . $section . '-' . time()) . '.' . $extension;

        return "events/{$event->id}/theme/{$section}/{$filename}";
    }

    /**
     * @param UploadedFile $file
     * @param string $assetType
     * @return UploadedFile|null
     * @throws Exception
     */
    private function processFileIfNeeded(UploadedFile $file, string $assetType): ?UploadedFile
    {
        $maxSize = $this->getSize($assetType);

        if ($file->getSize() > $maxSize) {
            throw new Exception("File size exceeds maximum allowed for {$assetType}");
        }

        return null;
    }

    /**
     * @param $theme
     * @param string $section
     * @param string $assetType
     * @param ThemeAsset $newAsset
     * @return void
     */
    private function deactivateOtherAssetsIfUnique(
        $theme,
        string $section,
        string $assetType,
        ThemeAsset $newAsset
    ): void {
        $uniqueAssetTypes = ['background_image', 'background_video', 'logo', 'favicon'];

        if (in_array($assetType, $uniqueAssetTypes)) {
            ThemeAsset::query()->where('event_theme_id', $theme->id)
                ->where('section', $section)
                ->where('asset_type', $assetType)
                ->where('id', '!=', $newAsset->id)
                ->update(['is_active' => false]);
        }
    }


    public function validateFile(UploadedFile $file, string $assetType): array
    {
        $errors = [];

        $allowedTypes = [
            'background_image' => ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'],
            'background_video' => ['video/mp4', 'video/webm'],
            'logo' => ['image/png', 'image/svg+xml', 'image/jpeg'],
            'favicon' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
            'custom' => []
        ];

        if ($assetType !== 'custom' && isset($allowedTypes[$assetType])) {
            if (!in_array($file->getMimeType(), $allowedTypes[$assetType])) {
                $errors[] = "Invalid file type for {$assetType}. Allowed: " . implode(', ', $allowedTypes[$assetType]);
            }
        }

        $maxSize = $this->getSize($assetType);
        if ($file->getSize() > $maxSize) {
            $errors[] = "File size (" . round($file->getSize() / 1024 / 1024, 2) . "MB) exceeds maximum allowed (" . round($maxSize / 1024 / 1024, 2) . "MB)";
        }

        return $errors;
    }

    /**
     * @param string $assetType
     * @return mixed
     */
    private function getSize(string $assetType): mixed
    {
        $maxSizes = [
            'background_image' => 5 * 1024 * 1024, // 5MB
            'background_video' => 50 * 1024 * 1024, // 50MB
            'logo' => 1 * 1024 * 1024, // 1MB
            'favicon' => 0.5 * 1024 * 1024, // 500KB
            'custom' => 10 * 1024 * 1024 // 10MB
        ];

        return $maxSizes[$assetType] ?? $maxSizes['custom'];
    }
}
