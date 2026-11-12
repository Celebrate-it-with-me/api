<?php

namespace App\Http\Services\AppServices\DressCode;

use App\Models\DressCode;
use App\Models\DressCodeImage;
use App\Models\Events;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DressCodeService
{
    /**
     * The dress code of an event as the screens read it. An event without one answers the same keys, empty.
     *
     * @return array<string, mixed>
     */
    public function get(Events $event): array
    {
        $dressCode = $event->dressCode()->with('dressCodeImages')->first();

        if (! $dressCode) {
            return [
                'id' => null,
                'dressCodeType' => null,
                'description' => '',
                'reservedColors' => [],
                'suggestedColors' => [],
                'images' => [],
            ];
        }

        return [
            'id' => $dressCode->id,
            'dressCodeType' => $dressCode->dress_code_type,
            'description' => $dressCode->description ?? '',
            'reservedColors' => array_values($dressCode->reserved_colors ?? []),
            'suggestedColors' => array_values($dressCode->suggested_colors ?? []),
            'images' => $this->images($dressCode),
        ];
    }

    /**
     * Create the event's dress code or replace it (an event has one). Pictures: the uploads in `keepImageIds`
     * stay, the other uploads are removed, `bankImages` is the full set of photo bank picks, and new files are added.
     *
     * @param  array<string, mixed>  $data  Validated by SaveDressCodeRequest
     * @param  list<UploadedFile>  $uploads
     */
    public function save(Events $event, array $data, array $uploads = []): array
    {
        DB::transaction(function () use ($event, $data, $uploads) {
            $dressCode = DressCode::query()->firstOrNew(['event_id' => $event->id]);
            $dressCode->fill([
                'dress_code_type' => $data['dressCodeType'],
                'description' => ($data['description'] ?? null) ?: null,
                'reserved_colors' => $this->normalizeColors($data['reservedColors'] ?? []),
                'suggested_colors' => $this->normalizeColors($data['suggestedColors'] ?? []),
            ])->save();

            $this->syncImages($dressCode, $data['keepImageIds'] ?? [], $data['bankImages'] ?? [], $uploads);
        });

        return $this->get($event->refresh());
    }

    public function delete(Events $event): void
    {
        $dressCode = $event->dressCode()->with('dressCodeImages')->first();

        if (! $dressCode) {
            return;
        }

        foreach ($dressCode->dressCodeImages as $image) {
            $this->deleteFile($image);
        }

        $dressCode->delete();
    }

    /** @param list<int|string> $keepIds @param list<string> $bankKeys @param list<UploadedFile> $uploads */
    private function syncImages(DressCode $dressCode, array $keepIds, array $bankKeys, array $uploads): void
    {
        $keepIds = array_map('intval', $keepIds);
        $bankKeys = array_values(array_unique($bankKeys));

        foreach ($dressCode->dressCodeImages()->get() as $image) {
            $stays = $image->source === 'bank' ? in_array($image->bank_key, $bankKeys, true) : in_array($image->id, $keepIds, true);

            if (! $stays) {
                $this->deleteFile($image);
                $image->delete();
            }
        }

        $present = $dressCode->dressCodeImages()->where('source', 'bank')->pluck('bank_key')->all();

        foreach (array_diff($bankKeys, $present) as $key) {
            DressCodeImage::query()->create(['dress_code_id' => $dressCode->id, 'source' => 'bank', 'bank_key' => $key]);
        }

        foreach ($uploads as $file) {
            $this->storeUpload($file, $dressCode->id);
        }
    }

    private function storeUpload(UploadedFile $file, int $dressCodeId): void
    {
        $path = $file->storeAs('dress-code-images', Str::uuid() . '.' . $file->getClientOriginalExtension(), 'public');

        DressCodeImage::query()->create([
            'dress_code_id' => $dressCodeId,
            'source' => 'upload',
            'image_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    private function deleteFile(DressCodeImage $image): void
    {
        if ($image->source === 'upload' && $image->image_path) {
            Storage::disk('public')->delete($image->image_path);
        }
    }

    /** Lowercase `#rrggbb`, no repeats. `#fff` becomes `#ffffff`. */
    private function normalizeColors(array $colors): array
    {
        return collect($colors)
            ->map(function (string $color) {
                $hex = strtolower(ltrim($color, '#'));

                return '#' . (strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex);
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<array{id: int, source: string, url: ?string, bankKey: ?string, originalName: ?string}> */
    private function images(DressCode $dressCode): array
    {
        return $dressCode->dressCodeImages
            ->map(fn (DressCodeImage $image) => [
                'id' => $image->id,
                'source' => $image->source,
                'url' => $image->source === 'upload' && $image->image_path ? Storage::disk('public')->url($image->image_path) : null,
                'bankKey' => $image->bank_key,
                'originalName' => $image->original_name,
            ])
            ->all();
    }
}
