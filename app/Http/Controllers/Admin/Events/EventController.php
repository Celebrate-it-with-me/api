<?php

namespace App\Http\Controllers\Admin\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Events\EventIndexRequest;
use App\Http\Services\Admin\Events\EventDirectory;
use App\Models\Events;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function __construct(private readonly EventDirectory $directory) {}

    public function index(EventIndexRequest $request): JsonResponse
    {
        return response()->json($this->directory->list($request->validated()));
    }

    public function show(Events $event): JsonResponse
    {
        return response()->json(['data' => $this->directory->summary($event)]);
    }
}
