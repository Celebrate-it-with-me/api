<?php

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
        'public' => env('PUBLIC_DISK_DRIVER', env('APP_ENV') === 'production' ? 's3' : 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'url' => env('AWS_URL'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'throw' => true,
            ]

            : [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => env('APP_URL') . '/storage',
                'visibility' => 'public',
                'throw' => false,
            ],

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

        'sweet-memories' => [
            'driver' => env('SWEET_MEMORIES_DRIVER', 'local'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'root' => env('SWEET_MEMORIES_DRIVER') === 'local'
                ? env('SWEET_MEMORIES_ROOT', storage_path('app/public/sweet-memories'))
                : null,
            'url' => (env('SWEET_MEMORIES_DRIVER') === 'local')
                ? env('APP_URL') . '/storage/sweet-memories'
                : env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'throw' => true,
        ],

        // Photos the organizers upload for their invitation (cover): WebP under images/event/<id>/cover/, public and
        // immutable by name. Local in development (served through the storage link), S3 in production (D-6).
        'event-media' => [
            'driver' => env('EVENT_MEDIA_DRIVER', 'local'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'root' => env('EVENT_MEDIA_DRIVER', 'local') === 'local'
                ? env('EVENT_MEDIA_ROOT', storage_path('app/public/event-media'))
                : null,
            'url' => env('EVENT_MEDIA_URL') ?: (env('EVENT_MEDIA_DRIVER', 'local') === 'local'
                ? env('APP_URL') . '/storage/event-media'
                : env('AWS_URL')),
            'visibility' => 'public',
            'endpoint' => env('AWS_ENDPOINT'),
            'throw' => true,
        ],

        // Sanitized files of the installed template packages (theme.css and assets/). Public, immutable by path
        // (<slug>/<version>-<hash8>/...) and meant to be served from an origin without cookies: TEMPLATE_PACKAGES_URL.
        'template-packages' => [
            'driver' => env('TEMPLATE_PACKAGES_DRIVER', 'local'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'root' => env('TEMPLATE_PACKAGES_DRIVER', 'local') === 'local'
                ? env('TEMPLATE_PACKAGES_ROOT', storage_path('app/public/template-packages'))
                : null,
            // Outside local/testing there is NO fallback: the files must come from an origin without cookies (W6-08).
            // Locally the API serves them itself, with the right headers, at /template-assets.
            'url' => env('TEMPLATE_PACKAGES_URL') ?: (in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? rtrim((string) env('APP_URL'), '/') . '/template-assets' : null),
            'visibility' => 'public',
            'endpoint' => env('AWS_ENDPOINT'),
            'throw' => true,
        ],
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
