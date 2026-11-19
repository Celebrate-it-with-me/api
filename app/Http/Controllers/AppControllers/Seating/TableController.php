<?php

namespace App\Http\Controllers\AppControllers\Seating;

use App\Exceptions\SeatingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\app\Seating\AssignGuestRequest;
use App\Http\Requests\app\Seating\BulkAssignRequest;
use App\Http\Requests\app\Seating\MoveGuestRequest;
use App\Http\Requests\app\Seating\StoreTableRequest;
use App\Http\Requests\app\Seating\UpdateTableRequest;
use App\Http\Resources\AppResources\Seating\SeatGuestResource;
use App\Http\Resources\AppResources\Seating\TableResource;
use App\Http\Services\AppServices\ModuleSupport;
use App\Http\Services\AppServices\Seating\TableServices;
use App\Models\Events;
use App\Models\Seating\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seating of an event. Reads need `view_event`, every change needs `edit_event` (owner and editor; a viewer never
 * writes). The route group already enforces it; each action repeats the check so it cannot be lost by moving a route.
 */
class TableController extends Controller
{
    public function __construct(
        protected TableServices $tableServices
    ) {}

    public function index(Request $request, Events $event): JsonResponse
    {
        $this->allow($request, $event, 'view_event');

        try {
            $result = $this->tableServices->getTablesForEvent($event);

            return response()->json([
                'success' => true,
                'data' => TableResource::collection($result['tables']),
                'meta' => $result['meta'],
            ]);
        } catch (\Throwable $th) {
            report($th);

            return response()->json(['success' => false, 'message' => __('seating.list_failed')], 500);
        }
    }

    public function store(StoreTableRequest $request, Events $event): JsonResponse
    {
        // The first table is what switches seating on: later ones are never blocked (a template change does not block).
        if (! Table::query()->where('event_id', $event->id)->exists()) {
            app(ModuleSupport::class)->assertCanActivate($event, 'seating');
        }

        $table = $this->tableServices->createTable($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('seating.table_created'),
            'data' => new TableResource($table->load('assignedGuests')),
        ], 201);
    }

    public function show(Request $request, Events $event, Table $table): JsonResponse
    {
        $this->allow($request, $event, 'view_event');
        abort_if($table->event_id !== $event->id, 404);

        return response()->json([
            'success' => true,
            'data' => new TableResource($table->load('assignedGuests')),
        ]);
    }

    public function update(UpdateTableRequest $request, Events $event, Table $table): JsonResponse
    {
        abort_if($table->event_id !== $event->id, 404);

        $table = $this->tableServices->updateTable($table, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('seating.table_updated'),
            'data' => new TableResource($table->load('assignedGuests')),
        ]);
    }

    public function destroy(Request $request, Events $event, Table $table): JsonResponse
    {
        $this->allow($request, $event, 'edit_event');
        abort_if($table->event_id !== $event->id, 404);

        $assignedCount = $this->tableServices->deleteTable($table);

        return response()->json([
            'success' => true,
            'message' => __('seating.table_deleted'),
            'meta' => ['guests_unassigned' => $assignedCount],
        ]);
    }

    public function assignGuest(AssignGuestRequest $request, Events $event, Table $table): JsonResponse
    {
        abort_if($table->event_id !== $event->id, 404);

        try {
            $assignment = $this->tableServices->assignGuest($table, (int) $request->guest_id, $request->user()->id);
        } catch (SeatingException $e) {
            return $this->rejected($e);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => __('seating.assign_failed')], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('seating.assigned'),
            'data' => new TableResource($table->refresh()->load('assignedGuests')),
            'meta' => ['declined' => $assignment->guest->rsvp_status === 'not-attending'],
        ]);
    }

    /**
     * Moves a seated guest to another table in one step. Body: `guest_id`, `to_table_id`. Answers the two tables as
     * they are after the move (`from` and `to`, same shape as `tables/{table}`).
     */
    public function moveGuest(MoveGuestRequest $request, Events $event): JsonResponse
    {
        try {
            [$from, $to, $guest] = $this->tableServices->moveGuest($event, (int) $request->guest_id, (int) $request->to_table_id, $request->user()->id);
        } catch (SeatingException $e) {
            return $this->rejected($e);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => __('seating.move_failed')], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('seating.moved'),
            'data' => [
                'from' => new TableResource($from->refresh()->load('assignedGuests')),
                'to' => new TableResource($to->refresh()->load('assignedGuests')),
            ],
            'meta' => ['declined' => $guest->rsvp_status === 'not-attending'],
        ]);
    }

    public function removeGuest(Request $request, Events $event, Table $table, int $guestId): JsonResponse
    {
        $this->allow($request, $event, 'edit_event');
        abort_if($table->event_id !== $event->id, 404);

        try {
            $removed = $this->tableServices->removeGuest($table, $guestId);

            if (! $removed) {
                return response()->json([
                    'success' => false,
                    'message' => __('seating.guest_not_at_table'),
                    'code' => 'guest_not_at_table',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => __('seating.removed'),
                'data' => new TableResource($table->refresh()->load('assignedGuests')),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => __('seating.remove_failed')], 500);
        }
    }

    /**
     * Guests without a table. Query: `search`, `status` (attending|pending|not-attending|all), `page`, `per_page`
     * (max 200; without page and per_page everyone is returned).
     */
    public function unassignedGuests(Request $request, Events $event): JsonResponse
    {
        $this->allow($request, $event, 'view_event');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,' . implode(',', TableServices::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $result = $this->tableServices->getUnassignedGuests($event, $filters);
        $paginator = $result['paginator'];

        return response()->json([
            'success' => true,
            'data' => SeatGuestResource::collection($result['rows']),
            'meta' => [
                // Everyone without a table, whatever the filters: the counter of the screen.
                'total_unassigned' => $result['counts']['total'],
                'attending_unassigned' => $result['counts']['attending'],
                'pending_unassigned' => $result['counts']['pending'],
                'declined_unassigned' => $result['counts']['declined'],
                // What the current filters match, and the page.
                'total' => $result['total'],
                'page' => $paginator?->currentPage() ?? 1,
                'per_page' => $paginator?->perPage() ?? $result['total'],
                'last_page' => $paginator?->lastPage() ?? 1,
            ],
        ]);
    }

    /**
     * Bulk assign. Body `assignments: [{table_id, guest_ids: []}]`. Always 200 when the body is valid; what could not
     * be seated comes back in `meta.errors` as `{code, table_id, guest_id, message}` (codes: table_full,
     * guest_already_seated, guest_not_in_event, guest_not_found, table_not_in_event).
     */
    public function bulkAssign(BulkAssignRequest $request, Events $event): JsonResponse
    {
        try {
            $result = $this->tableServices->bulkAssign($event, $request->validated('assignments'), $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => __('seating.bulk_done'),
                'meta' => [
                    'total_assigned' => $result['total_assigned'],
                    'total_skipped' => count($result['errors']),
                    'errors' => $result['errors'],
                    'declined_ids' => $result['declined_ids'],
                ],
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => __('seating.bulk_failed')], 500);
        }
    }

    public function clearAll(Request $request, Events $event): JsonResponse
    {
        $this->allow($request, $event, 'edit_event');

        try {
            $count = $this->tableServices->clearAllAssignments($event);

            return response()->json([
                'success' => true,
                'message' => __('seating.cleared'),
                'meta' => ['guests_unassigned' => $count],
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => __('seating.clear_failed')], 500);
        }
    }

    private function allow(Request $request, Events $event, string $permission): void
    {
        $user = $request->user();
        abort_unless($user && in_array($permission, $user->getEventPermissions($event), true), 403);
    }

    /** A seating rule that was not met: 422 with the stable `code` the app reads and a localized message. */
    private function rejected(SeatingException $e): JsonResponse
    {
        $errors = match ($e->errorCode) {
            'table_full' => ['table' => ['full']],
            'guest_already_seated' => ['guest_id' => [$e->getMessage()]],
            default => [],
        };

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
            'errors' => (object) $errors,
        ] + ($e->context ? ['context' => $e->context] : []), 422);
    }
}
