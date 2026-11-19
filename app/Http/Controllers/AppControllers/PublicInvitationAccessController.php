<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\InvitationAccessVerifyRequest;
use App\Http\Services\AppServices\InvitationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * The gate in front of /i/:slug (a link without guest code): "enter your code". Docs: docs/plans/invitation-code-gate-contract.md.
 * It reveals nothing but the organizer's first name, and only of a published event; every other case (unknown slug,
 * unpublished event, wrong code) looks exactly the same from outside.
 */
class PublicInvitationAccessController extends Controller
{
    public function __construct(private readonly InvitationAccessService $access) {}

    public function show(string $slug): JsonResponse
    {
        return response()->json(['data' => ['organizer_name' => $this->access->organizerFirstName($slug)]])
            ->header('Cache-Control', 'no-store');
    }

    public function verify(InvitationAccessVerifyRequest $request, string $slug): JsonResponse
    {
        $code = $this->access->normalizeCode((string) $request->validated('code'));
        $guest = $this->access->verify($slug, $code);

        if (! $guest) {
            // Never the whole code: a hash prefix lets support correlate attempts without keeping what was typed.
            Log::info('invitation access: wrong code', [
                'slug' => $slug,
                'ip' => $request->ip(),
                'code_hint' => substr($code, 0, 2) . '***',
                'code_length' => strlen($code),
            ]);

            return response()->json([
                'message' => __('errors.invitation_access.invalid_code'),
                'code' => 'invalid_code',
            ], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => ['valid' => true, 'code' => $guest->code]])
            ->header('Cache-Control', 'no-store');
    }
}
