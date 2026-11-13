<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\Onboarding;
use App\Models\Events;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function __construct(private readonly Onboarding $onboarding) {}

    public function show(Events $event): JsonResponse
    {
        return response()->json(['data' => $this->onboarding->summary($event)]);
    }
}
