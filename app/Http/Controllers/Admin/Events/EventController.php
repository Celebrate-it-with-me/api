<?php

namespace App\Http\Controllers\Admin\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Events\EventIndexRequest;
use App\Http\Services\Admin\Events\EventDirectory;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private readonly EventDirectory $directory) {}

    public function index(EventIndexRequest $request): JsonResponse
    {
        return response()->json($this->directory->list($request->validated()));
    }

    public function show(Request $request, Events $event): JsonResponse
    {
        // Support does not see payments or amounts (owner's decision): only who may manage purchases gets them.
        return response()->json(['data' => $this->directory->summary($event, $request->user()->can('admin.purchases.manage'))]);
    }
}
