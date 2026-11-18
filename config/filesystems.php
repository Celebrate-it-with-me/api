<?php

/*
 * Cloudflare R2 is S3-compatible. A disk whose DRIVER env is `r2` is built with the s3 driver and the R2_* keys; `s3`
 * keeps using AWS_*; `local` stays on disk. R2 ignores object ACLs, so no `visibility` is sent to a remote disk
 * (public reads come from the custom domain of the bucket, R2_URL / TEMPLATE_PACKAGES_URL).
 */
$remoteDisk = fn (string $bucketEnv, string $urlEnv): array => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
    'secret' => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
    'region' => env('R2_REGION', env('AWS_DEFAULT_REGION')),
    'bucket' => env($bucketEnv, env('AWS_BUCKET')),
    'url' => env($urlEnv, env('AWS_URL')),
    'endpoint' => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
    'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
    'throw' => true,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disk of the public media written by the app (avatars, location photos, dress-code images, background song).
    | `public` locally; `r2` on staging/production (MEDIA_DISK=r2) with no further code change. Always build URLs with
    | Storage::disk(config('filesystems.media_disk'))->url(), never with asset('storage/...').
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        // The disk of avatars, location photos, dress-code images and the background song. Local files are lost on a PaaS
        // (ephemeral disk): there it must be S3-compatible. PUBLIC_DISK_DRIVER=s3|local decides; unset keeps the old rule
        // (s3 in production, local anywhere else). Same AWS_* keys as the other disks; AWS_URL is the public base URL.
        'public' => (fn (string $driver) => $driver === 'local'
            ? [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => env('APP_URL') . '/storage',
                'visibility' => 'public',
                'throw' => false,
            ]
            : $remoteDisk('R2_BUCKET', 'R2_URL'))(env('PUBLIC_DISK_DRIVER', env('APP_ENV') === 'production' ? 's3' : 'local')),

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
        ],

        // Public media bucket on Cloudflare R2 (R2_URL = custom domain, ideally cookie-free, e.g. https://cdn.quinvite.com).
        'r2' => $remoteDisk('R2_BUCKET', 'R2_URL'),

        // Private bucket on R2 (invoices, exports): never has a public URL; serve through the API (stream) or
        // temporaryUrl(). INVOICING_DISK=r2_private. Falls back to R2_BUCKET with another prefix if unset.
        'r2_private' => $remoteDisk('R2_PRIVATE_BUCKET', 'R2_PRIVATE_URL'),

        // Sweet memories gallery (images + thumbnails). SWEET_MEMORIES_DRIVER=local|s3|r2.
        'sweet-memories' => env('SWEET_MEMORIES_DRIVER', 'local') === 'local'
            ? [
                'driver' => 'local',
                'root' => env('SWEET_MEMORIES_ROOT', storage_path('app/public/sweet-memories')),
                'url' => env('APP_URL') . '/storage/sweet-memories',
                'throw' => true,
            ]
            : $remoteDisk('R2_BUCKET', 'R2_URL'),

        // Photos the organizers upload for their invitation (cover): WebP under images/event/<id>/cover/, public and
        // immutable by name. Local in development (served through the storage link), s3/r2 on staging and production (D-6).
        // EVENT_MEDIA_DRIVER=local|s3|r2; EVENT_MEDIA_URL overrides the public base URL.
        'event-media' => env('EVENT_MEDIA_DRIVER', 'local') === 'local'
            ? [
                'driver' => 'local',
                'root' => env('EVENT_MEDIA_ROOT', storage_path('app/public/event-media')),
                'url' => env('EVENT_MEDIA_URL') ?: env('APP_URL') . '/storage/event-media',
                'visibility' => 'public',
                'throw' => true,
            ]
            : ['url' => env('EVENT_MEDIA_URL', env('R2_URL', env('AWS_URL')))] + $remoteDisk('R2_BUCKET', 'R2_URL'),

        // Sanitized files of the installed template packages (theme.css and assets/). Public, immutable by path
        // (<slug>/<version>-<hash8>/...) and meant to be served from an origin without cookies: TEMPLATE_PACKAGES_URL.
        // TEMPLATE_PACKAGES_DRIVER=local|s3|r2; on r2 they live in their own bucket (R2_PACKAGES_BUCKET).
        'template-packages' => env('TEMPLATE_PACKAGES_DRIVER', 'local') === 'local'
            ? [
                'driver' => 'local',
                'root' => env('TEMPLATE_PACKAGES_ROOT', storage_path('app/public/template-packages')),
                // Outside local/testing there is NO fallback: the files must come from an origin without cookies (W6-08).
                // Locally the API serves them itself, with the right headers, at /template-assets.
                'url' => env('TEMPLATE_PACKAGES_URL') ?: (in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? rtrim((string) env('APP_URL'), '/') . '/template-assets' : null),
                'visibility' => 'public',
                'throw' => true,
            ]
            : ['url' => env('TEMPLATE_PACKAGES_URL') ?: null] + $remoteDisk('R2_PACKAGES_BUCKET', 'TEMPLATE_PACKAGES_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
