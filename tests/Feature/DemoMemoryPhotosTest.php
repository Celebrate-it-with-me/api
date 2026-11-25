<?php

use App\Models\SweetMemoriesImage;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('seeds sample party photos for the demo quince, with coherent years and small files', function () {
    Storage::fake('sweet-memories');
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    $images = SweetMemoriesImage::query()->orderBy('order')->get();

    expect($images)->toHaveCount(3)
        ->and($images->pluck('year')->all())->toBe(['2013', '2018', '2024'])
        ->and($images->pluck('title')->join(' '))->not->toContain('Recuerdo');

    foreach ($images as $image) {
        Storage::disk('sweet-memories')->assertExists([$image->image_path, $image->thumbnail_path]);
        expect(Storage::disk('sweet-memories')->size($image->image_path))->toBeLessThan(200 * 1024)
            ->and(getimagesizefromstring(Storage::disk('sweet-memories')->get($image->image_path))[0])->toBe(800);
    }

    // Seeding again does not duplicate them.
    $this->seed(DemoSeeder::class);
    expect(SweetMemoriesImage::count())->toBe(3);
});
