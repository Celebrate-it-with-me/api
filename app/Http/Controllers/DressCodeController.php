<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDressCodeRequest;
use App\Http\Services\AppServices\DressCode\DressCodeService;
use App\Models\Events;
use Illuminate\Http\JsonResponse;

/** The dress code of an event (it has at most one). Access to the event is checked by `event.access` on the route. */
class DressCodeController extends Controller
{
    public function __construct(private readonly DressCodeService $dressCodeService) {}

    public function index(Events $event): JsonResponse
    {
        return response()->json(['data' => $this->dressCodeService->get($event)]);
    }

    /** Create or replace. */
    public function store(SaveDressCodeRequest $request, Events $event): JsonResponse
    {
        $data = $this->dressCodeService->save($event, $request->validated(), $request->file('dressCodeImages') ?? []);

        return response()->json(['data' => $data]);
    }

    public function destroy(Events $event): JsonResponse
    {
        $this->dressCodeService->delete($event);

        return response()->json(['message' => 'Dress code removed.']);
    }
}
