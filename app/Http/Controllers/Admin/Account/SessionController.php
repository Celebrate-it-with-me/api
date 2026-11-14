<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\AdminAudit;
use App\Models\ImpersonationSession;
use App\Support\ClientInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** The active sessions (tokens) of the logged-in person: list, close one, close the others, sign out, sign out everywhere. */
class SessionController extends Controller
{
    public function __construct(private readonly AccountSecurity $security, private readonly AdminAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $current = $this->security->currentToken($request->user());
        $tokens = $this->own($request)
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'current' => $current && $t->is($current),
                'device' => ClientInfo::device($t->user_agent),
                'name' => $t->name,
                // 'admin' = panel; null = the app of the organizers or a token from before this was recorded.
                'client' => $t->client,
                'ip' => ClientInfo::maskIp($t->ip_address),
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'createdAt' => $t->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $tokens->values()]);
    }

    /** Closes one session of hers. Someone else's id is a 404, never a hint that it exists. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $this->own($request)->whereKey($id)->first();
        abort_unless($token, 404);

        $current = $this->security->currentToken($request->user());
        if ($current && $token->is($current)) {
            return response()->json(['message' => 'To close this session use sign out.', 'code' => 'current_session'], 422);
        }

        $device = ClientInfo::device($token->user_agent);
        $token->delete();
        $this->audit->record('account.session_revoked', $request->user(), ['after' => ['sessionId' => $id, 'device' => $device]]);

        return response()->json(['data' => ['closed' => 1]]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $closed = $this->security->revokeTokens($request->user(), $this->security->currentToken($request->user()));
        $this->audit->record('account.sessions_revoked', $request->user(), ['after' => ['count' => $closed]]);

        return response()->json(['data' => ['closed' => $closed]]);
    }

    /** Signs out of this device: the token stops working on the server, not only in the browser. */
    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('account.logout', $request->user());
        $this->security->currentToken($request->user())?->delete();

        return response()->json(['data' => ['closed' => 1]]);
    }

    /** Signs out everywhere, this device included. */
    public function logoutAll(Request $request): JsonResponse
    {
        $closed = $this->security->revokeTokens($request->user());
        $this->audit->record('account.logout_all', $request->user(), ['after' => ['count' => $closed]]);

        return response()->json(['data' => ['closed' => $closed]]);
    }

    /** Her own tokens that are alive; a support-session token is never listed (nor closable from here). */
    private function own(Request $request)
    {
        return $request->user()->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('abilities')->orWhere('abilities', 'not like', '%"' . ImpersonationSession::ABILITY . '"%'));
    }
}
