<?php

namespace App\Http\Controllers\AppControllers\Announcements;

use App\Http\Controllers\Controller;
use App\Http\Services\Announcements\AnnouncementService;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $lang = $request->query('lang');
        if (! in_array($lang, ['es', 'en'], true)) {
            $lang = $user->preferences?->language === 'en' ? 'en' : 'es';
        }

        return response()->json(['data' => $this->announcements->forUser($user)->map(function (Announcement $a) use ($lang) {
            $hasButton = $a->cta_url !== null && $a->localized($lang, 'cta_label') !== null;

            return [
                'id' => $a->id,
                'level' => $a->level,
                'title' => $a->localized($lang, 'title'),
                'body' => $a->localized($lang, 'body'),
                'ctaLabel' => $hasButton ? $a->localized($lang, 'cta_label') : null,
                'ctaUrl' => $hasButton ? $a->cta_url : null,
                'dismissible' => $a->dismissible,
            ];
        })->all()]);
    }

    public function dismiss(Request $request, int $announcement): JsonResponse
    {
        $user = $request->user();
        $a = $this->announcements->visibleTo($user, $announcement);
        if (! $a) {
            return response()->json(['message' => 'Announcement not found.', 'code' => 'announcement_not_found'], 404);
        }
        if (! $a->dismissible) {
            return response()->json(['message' => 'This announcement cannot be dismissed.', 'errors' => ['announcement' => ['not_dismissible']]], 422);
        }
        $this->announcements->dismiss($a, $user);

        return response()->json(['data' => null]);
    }
}
