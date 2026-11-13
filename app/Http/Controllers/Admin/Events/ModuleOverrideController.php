<?php

namespace App\Http\Controllers\Admin\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Events\ModuleOverrideRequest;
use App\Http\Services\Admin\Events\ModuleOverrides;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ModuleOverrideController extends Controller
{
    public function __construct(private readonly ModuleOverrides $overrides) {}

    public function index(Events $event): JsonResponse
    {
        return response()->json(['data' => $this->overrides->list($event)]);
    }

    public function update(ModuleOverrideRequest $request, Events $event, string $module): JsonResponse
    {
        $data = $request->validated();
        $expires = ! empty($data['expiresAt']) ? Carbon::parse($data['expiresAt']) : null;

        return response()->json(['data' => $this->overrides->set($event, $module, $data['state'], $expires, $data['reason'])]);
    }

    public function destroy(ModuleOverrideRequest $request, Events $event, string $module): JsonResponse
    {
        return response()->json(['data' => $this->overrides->remove($event, $module, $request->validated()['reason'])]);
    }
}
