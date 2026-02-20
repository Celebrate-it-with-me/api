<?php

namespace App\Http\Controllers\AppControllers\Seating;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\Seating\AssignGuestRequest;
use App\Http\Requests\app\Seating\StoreTableRequest;
use App\Http\Requests\app\Seating\UpdateTableRequest;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Resources\AppResources\Seating\TableResource;
use App\Http\Services\AppServices\Seating\TableServices;
use App\Models\Events;
use App\Models\Seating\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function __construct(
        protected TableServices $tableServices
    ) {}

    /**
     * Display a listing of tables for an event.
     */
    public function index(Events $event): JsonResponse
    {
        //$this->authorize('view', $event);
        try {
            $result = $this->tableServices->getTablesForEvent($event);
            
            return response()->json([
                'success' => true,
                'data' => TableResource::collection($result['tables']),
                'meta' => $result['meta']
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tables for event.',
                'error' => $th->getMessage() . ' ' . $th->getFile() . ' ' . $th->getLine(),
            ], 500);
        }
    }
    
    /**
     * Store a newly created table.
     */
    public function store(StoreTableRequest $request, Events $event): JsonResponse
    {
        $table = $this->tableServices->createTable($request->validated());
        
        return response()->json([
            'success' => true,
            'message' => 'Table created successfully.',
            'data' => new TableResource($table->load('assignedGuests')),
        ], 201);
    }
    
    /**
     * Display the specified table.
     */
    public function show(Events $event, Table $table): JsonResponse
    {
        //$this->authorize('view', $event);
        
        abort_if($table->event_id !== $event->id, 404);
        
        $table->load(['assignedGuests']);
        
        return response()->json([
            'success' => true,
            'data' => new TableResource($table),
        ]);
    }
    
    /**
     * Update the specified table.
     */
    public function update(UpdateTableRequest $request, Events $event, Table $table): JsonResponse
    {
        abort_if($table->event_id !== $event->id, 404);
        
        $originalName = $table->getOriginal('name');
        $table = $this->tableServices->updateTable($table, $request->validated(), $originalName);
        
        return response()->json([
            'success' => true,
            'message' => 'Table updated successfully.',
            'data' => new TableResource($table->load('assignedGuests')),
        ]);
    }
    
    /**
     * Remove the specified table.
     */
    public function destroy(Events $event, Table $table): JsonResponse
    {
        //$this->authorize('manage', $event);
        
        abort_if($table->event_id !== $event->id, 404);
        
        $assignedCount = $this->tableServices->deleteTable($table);
        
        return response()->json([
            'success' => true,
            'message' => 'Table deleted successfully.',
            'meta' => [
                'guests_unassigned' => $assignedCount,
            ]
        ]);
    }
    
    /**
     * Assign a guest to a table.
     */
    public function assignGuest(AssignGuestRequest $request, Events $event, Table $table): JsonResponse
    {
        abort_if($table->event_id !== $event->id, 404);
        
        try {
            $this->tableServices->assignGuest($table, $request->guest_id, $request->user()->id);
            
            return response()->json([
                'success' => true,
                'message' => 'Guest assigned successfully.',
                'data' => new TableResource($table->load('assignedGuests')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign guest.',
                'error' => $e->getMessage(). ' ' . $e->getFile() . ' ' . $e->getLine(),
            ], 500);
        }
    }
    
    /**
     * Remove a guest from a table.
     */
    public function removeGuest(Request $request, Events $event, Table $table, int $guestId): JsonResponse
    {
        // $this->authorize('manage', $event);
        
        abort_if($table->event_id !== $event->id, 404);
        
        try {
            $removed = $this->tableServices->removeGuest($table, $guestId);
            
            if (!$removed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Guest is not assigned to this table.',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Guest removed from table successfully.',
                'data' => new TableResource($table->load('assignedGuests')),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove guest.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get unassigned guests for an event.
     */
    public function unassignedGuests(Events $event): JsonResponse
    {
        // $this->authorize('view', $event);
        
        $unassignedGuests = $this->tableServices->getUnassignedGuests($event);
        
        return response()->json([
            'success' => true,
            'data' => GuestResource::collection($unassignedGuests),
            'meta' => [
                'total_unassigned' => $unassignedGuests->count(),
            ]
        ]);
    }
    
    /**
     * Bulk assign guests to tables.
     */
    public function bulkAssign(Request $request, Events $event): JsonResponse
    {
        // $this->authorize('manage', $event);
        
        $request->validate([
            'assignments' => 'required|array',
            'assignments.*.table_id' => 'required|exists:tables,id',
            'assignments.*.guest_ids' => 'required|array',
            'assignments.*.guest_ids.*' => 'required|exists:guests,id',
        ]);
        
        try {
            $result = $this->tableServices->bulkAssign($event, $request->assignments, $request->user()->id);
            
            return response()->json([
                'success' => true,
                'message' => 'Bulk assignment completed.',
                'meta' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk assignment failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Clear all assignments for an event.
     */
    public function clearAll(Events $event): JsonResponse
    {
        //$this->authorize('manage', $event);
        
        try {
            $count = $this->tableServices->clearAllAssignments($event);
            
            return response()->json([
                'success' => true,
                'message' => 'All table assignments cleared.',
                'meta' => [
                    'guests_unassigned' => $count,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear assignments.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
