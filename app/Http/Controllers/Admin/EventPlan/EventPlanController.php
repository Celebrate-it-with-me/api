<?php

namespace App\Http\Controllers\Admin\EventPlan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventPlan\ChangePlanRequest;
use App\Http\Services\Admin\EventPlan\EventPlanManager;
use App\Models\Events;
use Illuminate\Http\JsonResponse;

class EventPlanController extends Controller
{
    public function __construct(private readonly EventPlanManager $manager) {}

    public function purchases(Events $event): JsonResponse
    {
        return response()->json($this->manager->purchases($event));
    }

    public function changePlan(ChangePlanRequest $request, Events $event): JsonResponse
    {
        return response()->json(['data' => $this->manager->changePlan($event, $request->validated('planSlug'), $request->validated('reason'))]);
    }
}
