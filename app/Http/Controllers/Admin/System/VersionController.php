<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VersionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'version' => config('version.version'),
            'commit' => config('version.commit'),
            'builtAt' => config('version.built_at'),
            'environment' => app()->environment(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    }
}
