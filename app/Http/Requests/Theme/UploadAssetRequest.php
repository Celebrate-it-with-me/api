<?php

namespace App\Http\Requests\Theme;

use Illuminate\Foundation\Http\FormRequest;

class UploadAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:10240', // 10MB max
            'section' => 'required|string|in:hero,rsvp,save_the_date,location,details,gallery,global',
            'asset_type' => 'required|string|in:background_image,background_video,logo,favicon,custom',
            'alt_text' => 'sometimes|string|max:255',
            'sort_order' => 'sometimes|integer|min:0'
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');
            $assetType = $this->input('asset_type');

            if ($file && $assetType) {
                $this->validateFileTypeForAsset($validator, $file, $assetType);
            }
        });
    }

    private function validateFileTypeForAsset($validator, $file, $assetType): void
    {
        $allowedTypes = [
            'background_image' => ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'],
            'background_video' => ['video/mp4', 'video/webm'],
            'logo' => ['image/png', 'image/svg+xml', 'image/jpeg'],
            'favicon' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
            'custom' => []
        ];

        if ($assetType !== 'custom' && isset($allowedTypes[$assetType])) {
            if (!in_array($file->getMimeType(), $allowedTypes[$assetType])) {
                $validator->errors()->add('file', "Invalid file type for {$assetType}");
            }
        }
    }
}
