<?php

/*
 * Release metadata exposed to staff by GET /api/v1/admin/system/version.
 * `version` comes from the VERSION file at the repo root; `commit` and `built_at` are set by the deploy
 * (APP_COMMIT / APP_BUILT_AT; GIT_COMMIT / BUILT_AT are accepted too). Without them both are null.
 */

$file = base_path('VERSION');
$version = is_file($file) ? trim((string) file_get_contents($file)) : '';

return [
    'version' => $version !== '' ? $version : 'unknown',
    'commit' => env('APP_COMMIT', env('GIT_COMMIT')) ?: null,
    'built_at' => env('APP_BUILT_AT', env('BUILT_AT')) ?: null,
];
