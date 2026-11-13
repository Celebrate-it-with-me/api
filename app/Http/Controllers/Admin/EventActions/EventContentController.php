<?php

namespace App\Http\Controllers\Admin\EventActions;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\EventActions\EventContentService;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventContentController extends Controller
{
    public function __construct(private readonly EventContentService $content) {}

    public function __invoke(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate([
            'section' => ['required', Rule::in(EventContentService::SECTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        return response()->json($this->content->page($event, $f['section'], $f['page'] ?? 1, $f['perPage'] ?? 20));
    }
}
