<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\Roster\GuestImport;
use App\Http\Services\AppServices\Roster\ImportProblem;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Import guests from a spreadsheet: template, preview and confirm. */
class GuestImportController extends Controller
{
    public function __construct(private readonly GuestImport $import) {}

    public function template(Events $event): Response
    {
        return response($this->import->template($event), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . (($event->locale ?? 'es') === 'en' ? 'guests-template' : 'plantilla-invitados') . '.csv"',
        ]);
    }

    public function preview(Request $request, Events $event): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt,xlsx,xls']]);

        try {
            return response()->json(['data' => $this->import->preview($event, $request->file('file'))]);
        } catch (ImportProblem $problem) {
            return response()->json(['message' => 'The file cannot be imported.', 'errors' => ['file' => [$problem->getMessage()]]], 422);
        }
    }

    public function store(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:' . GuestImport::MAX_ROWS],
            'rows.*.name' => ['nullable', 'string', 'max:120'],
            'rows.*.phone' => ['nullable', 'string', 'max:30'],
            'rows.*.email' => ['nullable', 'string', 'max:255'],
            'rows.*.family' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->import->commit($event, $data['rows'])], 201);
    }
}
