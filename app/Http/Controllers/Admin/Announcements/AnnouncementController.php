<?php

namespace App\Http\Controllers\Admin\Announcements;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Announcements\AnnouncementRequest;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Announcements\AnnouncementAudience;
use App\Http\Services\Announcements\AnnouncementService;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements, private readonly AnnouncementAudience $audiences) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Announcement::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['*' => 'invalid']);
        $page = (int) ($f['page'] ?? 1);
        $perPage = (int) ($f['per_page'] ?? 25);
        [$rows, $total] = $this->announcements->list($f['status'] ?? null, $page, $perPage);

        return response()->json([
            'data' => $rows->map(fn (Announcement $a) => $this->present($a))->all(),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total],
        ]);
    }

    public function store(AnnouncementRequest $request): JsonResponse
    {
        $a = $this->announcements->create($request->validated(), $request->user());

        return response()->json(['data' => $this->present($a)], 201);
    }

    public function update(AnnouncementRequest $request, int $id): JsonResponse
    {
        $a = Announcement::find($id);

        return $a
            ? response()->json(['data' => $this->present($this->announcements->update($a, $request->validated()))])
            : $this->notFound();
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()], ['reason.required' => 'required', 'reason.*' => 'invalid']);
        $a = Announcement::find($id);
        if (! $a) {
            return $this->notFound();
        }
        $this->announcements->delete($a, $data['reason']);

        return response()->json(['data' => null]);
    }

    public function audiencePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'upcoming_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], ['audience.required' => 'required', 'audience.in' => 'invalid_audience', 'upcoming_days.*' => 'invalid_days']);
        if ($data['audience'] === 'upcoming_event' && empty($data['upcoming_days'])) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => ['upcoming_days' => ['upcoming_days_required']]], 422);
        }

        return response()->json(['data' => ['count' => $this->audiences->count($data['audience'], $data['upcoming_days'] ?? null)]]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Announcement not found.', 'code' => 'announcement_not_found'], 404);
    }

    private function present(Announcement $a): array
    {
        return [
            'id' => $a->id,
            'level' => $a->level,
            'titleEs' => $a->title_es,
            'titleEn' => $a->title_en,
            'bodyEs' => $a->body_es,
            'bodyEn' => $a->body_en,
            'ctaLabelEs' => $a->cta_label_es,
            'ctaLabelEn' => $a->cta_label_en,
            'ctaUrl' => $a->cta_url,
            'dismissible' => $a->dismissible,
            'audience' => $a->audience,
            'upcomingDays' => $a->upcoming_days,
            'startsAt' => $a->starts_at?->toIso8601String(),
            'endsAt' => $a->ends_at?->toIso8601String(),
            'isActive' => $a->is_active,
            'status' => $a->status(),
            'dismissedCount' => (int) ($a->dismissals_count ?? $a->dismissals()->count()),
            'createdAt' => $a->created_at?->toIso8601String(),
            'updatedAt' => $a->updated_at?->toIso8601String(),
        ];
    }
}
