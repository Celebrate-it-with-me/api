<?php

use App\Http\Controllers\TemplateAssetController;
use App\Http\Services\Admin\Templates\Packages\PackageFiles;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Storage readiness (Cloudflare R2): the media disk is chosen by MEDIA_DISK, never hard-coded to `public`.

it('defaults the media disk to public so local behaviour is unchanged', function () {
    expect(config('filesystems.media_disk'))->toBe('public');
});

it('builds the r2 disks with the s3 driver and the R2 settings, without sending ACLs', function () {
    foreach (['r2', 'r2_private'] as $name) {
        $disk = config("filesystems.disks.$name");
        expect($disk['driver'])->toBe('s3')->and($disk)->not->toHaveKey('visibility')->and($disk['throw'])->toBeTrue();
    }
    expect(config('filesystems.disks.public.driver'))->toBe('local');
});

it('reads, writes, deletes and builds URLs through whatever disk MEDIA_DISK names', function () {
    config(['filesystems.media_disk' => 'media-fake', 'filesystems.disks.media-fake' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/media-fake'), 'url' => 'https://cdn.example.test']]);
    Storage::fake('media-fake', ['url' => 'https://cdn.example.test']);

    $path = UploadedFile::fake()->image('a.jpg')->store('avatars', config('filesystems.media_disk'));
    Storage::disk('media-fake')->assertExists($path);

    $user = (new User)->forceFill(['avatar' => $path]);
    expect($user->avatar_url)->toBe('https://cdn.example.test/' . $path);

    Storage::disk(config('filesystems.media_disk'))->delete($path);
    Storage::disk('media-fake')->assertMissing($path);
});

it('serves template package files from a non-local disk', function () {
    config(['filesystems.disks.pkg-remote' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/pkg-remote')], 'invitation.packages.disk' => 'pkg-remote']);
    Storage::fake('pkg-remote');
    $path = 'rose/1.0.0-0123456789abcdef/theme.css';
    Storage::disk('pkg-remote')->put($path, ':root{--c:#fff}');

    expect(TemplateAssetController::validPath($path))->toBeTrue();
    $this->get("/template-assets/{$path}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((new PackageFiles)->exists('rose/1.0.0-0123456789abcdef'))->toBeTrue();
});

it('builds package URLs from TEMPLATE_PACKAGES_URL of the configured disk', function () {
    config(['filesystems.disks.template-packages.url' => 'https://packages.example.test/']);

    expect((new PackageFiles)->url('rose/1.0.0-0123456789abcdef/theme.css'))->toBe('https://packages.example.test/rose/1.0.0-0123456789abcdef/theme.css');
});
