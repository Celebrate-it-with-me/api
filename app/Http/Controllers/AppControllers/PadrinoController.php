<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\Padrinos\CommitmentsRequest;
use App\Http\Requests\app\Padrinos\PadrinoRequest;
use App\Http\Services\Padrinos\PadrinoService;
use App\Models\Events;
use App\Models\Padrino;
use Illuminate\Http\JsonResponse;

/** The padrinos of an event. Access is checked by `event.access`; each padrino must belong to the event (404). */
class PadrinoController extends Controller
{
    public function __construct(private readonly PadrinoService $padrinos) {}

    public function index(Events $event): JsonResponse
    {
        $rows = $this->padrinos->list($event);

        return response()->json(['data' => $rows, 'totals' => $this->padrinos->totals($rows), 'currency' => $event->currency ?: 'USD']);
    }

    public function store(PadrinoRequest $request, Events $event): JsonResponse
    {
        $padrino = $this->padrinos->create($event, $request->padrino());

        return response()->json(['data' => $this->padrinos->row($padrino)], 201);
    }

    public function update(PadrinoRequest $request, Events $event, Padrino $padrino): JsonResponse
    {
        $this->own($event, $padrino);
        $padrino->update($request->padrino());

        return response()->json(['data' => $this->padrinos->row($padrino->refresh())]);
    }

    /** A padrino who already paid something cannot be removed: those payments would lose who made them. */
    public function destroy(Events $event, Padrino $padrino): JsonResponse
    {
        $this->own($event, $padrino);

        if ($padrino->payments()->exists()) {
            return response()->json(['message' => 'This padrino has payments.', 'errors' => ['padrino' => ['has_payments']]], 422);
        }

        $padrino->delete();

        return response()->json(['message' => 'Padrino removed.']);
    }

    public function setCommitments(CommitmentsRequest $request, Events $event, Padrino $padrino): JsonResponse
    {
        $this->own($event, $padrino);

        return response()->json(['data' => $this->padrinos->row($this->padrinos->setCommitments($padrino, $request->validated('commitments')))]);
    }

    private function own(Events $event, Padrino $padrino): void
    {
        abort_unless($padrino->event_id === $event->id, 404);
    }
}
