<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Moderation\ModerationItemNotFound;
use App\Http\Services\Admin\Moderation\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    public function __construct(private readonly ModerationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'type' => ['nullable', Rule::in(ModerationService::TYPES)],
            'status' => ['nullable', Rule::in(['visible', 'hidden'])],
            'eventId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $page = $this->service->queue($f, $f['perPage'] ?? 25);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ]);
    }

    public function hide(Request $request, string $type, int $id): JsonResponse
    {
        return $this->single($request, 'hide', $type, $id, true);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        return $this->single($request, 'restore', $type, $id, false);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        return $this->single($request, 'delete', $type, $id, true);
    }

    public function bulk(Request $request): JsonResponse
    {
        $d = $request->validate([
            'action' => ['required', Rule::in(['hide', 'restore', 'delete'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type' => ['required', Rule::in(ModerationService::TYPES)],
            'items.*.id' => ['required', 'integer'],
            // Restoring needs no reason; hiding and deleting do.
            'reason' => $request->input('action') === 'restore' ? ['nullable', 'string', 'max:500'] : AdminAudit::reasonRule(),
        ]);

        $results = [];
        $seen = [];
        foreach ($d['items'] as $item) {
            $key = $item['type'] . ':' . $item['id'];
            if (isset($seen[$key])) {
                continue; // a repeated item is processed once
            }
            $seen[$key] = true;

            $results[] = ['type' => $item['type'], 'id' => (int) $item['id']] + $this->attempt($d['action'], $item['type'], (int) $item['id'], $d['reason'] ?? null, $request);
        }

        return response()->json(['data' => [
            'action' => $d['action'],
            'succeeded' => count(array_filter($results, fn ($r) => $r['ok'])),
            'failed' => count(array_filter($results, fn ($r) => ! $r['ok'])),
            'results' => $results,
        ]]);
    }

    /** One item of a bulk run: its own transaction, so a failure never undoes the others. */
    private function attempt(string $action, string $type, int $id, ?string $reason, Request $request): array
    {
        try {
            $r = $this->service->apply($action, $type, $id, $reason, $request->user());

            return ['ok' => true, 'changed' => $r['changed'], 'error' => null];
        } catch (ModerationItemNotFound) {
            return ['ok' => false, 'changed' => false, 'error' => ['code' => 'not_found', 'message' => 'El elemento ya no existe.']];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'changed' => false, 'error' => ['code' => $e->getMessage() === 'storage_failed' ? 'storage_failed' : 'error', 'message' => 'No se pudo completar la acción.']];
        }
    }

    private function single(Request $request, string $action, string $type, int $id, bool $needsReason): JsonResponse
    {
        abort_unless(in_array($type, ModerationService::TYPES, true), 404);
        $d = $request->validate(['reason' => $needsReason ? AdminAudit::reasonRule() : ['nullable', 'string', 'max:500']]);

        try {
            $r = $this->service->apply($action, $type, $id, $d['reason'] ?? null, $request->user());
        } catch (ModerationItemNotFound) {
            abort(404);
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'No se pudieron borrar los archivos de la foto; no se borró nada.', 'code' => 'storage_failed'], 500);
        }

        return response()->json(['data' => ['changed' => $r['changed'], 'item' => $r['item']]]);
    }
}
