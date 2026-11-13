<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\ToggleModuleRequest;
use App\Http\Requests\Admin\Catalog\UpdateModulePlansRequest;
use App\Http\Requests\Admin\Catalog\UpdatePlanRequest;
use App\Http\Services\Admin\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->overview()]);
    }

    public function impact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', 'string', 'exists:modules,key'],
            'planSlugs' => ['present', 'array'],
            'planSlugs.*' => ['string', 'distinct', 'exists:event_plans,slug'],
        ]);

        return response()->json(['data' => $this->catalog->impact($data['module'], $data['planSlugs'])]);
    }

    public function updatePlans(UpdateModulePlansRequest $request, string $module): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->catalog->updatePlans(
            $module,
            $data['planSlugs'],
            $data['reason'],
            (bool) ($data['keepForExisting'] ?? true),
        )]);
    }

    public function toggle(ToggleModuleRequest $request, string $module): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->catalog->toggle($module, (bool) $data['isEnabled'], $data['reason'])]);
    }

    public function updatePlan(UpdatePlanRequest $request, string $slug): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'];
        unset($data['reason']);

        return response()->json(['data' => $this->catalog->updatePlan($slug, $data, $reason)]);
    }

    public function planImpact(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['maxGuests' => ['required', 'integer', 'min:0', 'max:100000']]);

        return response()->json(['data' => $this->catalog->planImpact($slug, (int) $data['maxGuests'])]);
    }
}
