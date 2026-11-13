<?php

namespace App\Http\Controllers\Admin\EventActions;

use App\Exceptions\PlanLimitExceeded;
use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\EventActions\StaffEventActions;
use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventActionController extends Controller
{
    public function __construct(private readonly StaffEventActions $actions) {}

    public function lifecycle(Request $request, Events $event, string $action): JsonResponse
    {
        $d = $request->validate([
            'reason' => AdminAudit::reasonRule(),
            'stage' => ['nullable', Rule::in([Events::STAGE_SAVE_THE_DATE, Events::STAGE_INVITATION])],
        ]);

        try {
            $updated = $this->actions->lifecycle($event, $action, $d['reason'], $request->user(), $d['stage'] ?? null);
        } catch (PlanLimitExceeded $e) {
            return response()->json([
                'message' => "La lista tiene {$e->total} invitados y el plan del evento permite {$e->limit}. Hay que quitar " . ($e->total - $e->limit) . ' o desbloquear el evento antes de publicarlo.',
                'code' => $e->reason, 'limit' => $e->limit, 'total' => $e->total, 'excess' => $e->total - $e->limit,
            ], 402);
        }

        return response()->json(['data' => ['id' => $updated->id, 'status' => $updated->status, 'stage' => $updated->invitation_stage]]);
    }

    /** Collaboration invites of the event, so the tab can offer "resend" on the pending ones. */
    public function invites(Events $event): JsonResponse
    {
        $rows = EventCollaborationInvite::where('event_id', $event->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (EventCollaborationInvite $i) => [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role,
            'status' => $i->status === 'pending' && $i->isExpired() ? 'expired' : $i->status,
            'sentAt' => $i->sent_at ? Carbon::parse($i->sent_at)->toIso8601String() : null,
            'expiresAt' => $i->expires_at?->toIso8601String(),
            'resendCount' => (int) $i->resend_count,
        ])->values()]);
    }

    public function resendOwnerEmail(Request $request, Events $event): JsonResponse
    {
        $d = $request->validate(['reason' => AdminAudit::reasonRule()]);

        return response()->json(['data' => ['sent' => $this->actions->resendOwnerEmail($event, $d['reason'], $request->user())]]);
    }

    public function resendCollaboratorInvite(Request $request, Events $event, EventCollaborationInvite $invite): JsonResponse
    {
        $d = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $invite = $this->actions->resendCollaboratorInvite($event, $invite, $d['reason'], $request->user());

        return response()->json(['data' => ['id' => $invite->id, 'resendCount' => $invite->resend_count, 'sentAt' => $invite->sent_at ? Carbon::parse($invite->sent_at)->toIso8601String() : null]]);
    }

    public function slug(Request $request, Events $event): JsonResponse
    {
        $d = $request->validate([
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('events', 'custom_url_slug')->ignore($event->id)],
            'reason' => AdminAudit::reasonRule(),
        ]);
        if ($d['slug'] === $event->custom_url_slug) {
            throw ValidationException::withMessages(['slug' => 'Ese ya es el enlace actual.']);
        }

        $old = $event->custom_url_slug;
        $updated = $this->actions->changeSlug($event, $d['slug'], $d['reason'], $request->user());

        return response()->json(['data' => [
            'slug' => $updated->custom_url_slug,
            'previousSlug' => $old,
            'warning' => 'Los enlaces ya enviados a los invitados con el enlace anterior dejarán de funcionar.',
        ]]);
    }

    public function transfer(Request $request, Events $event): JsonResponse
    {
        $d = $request->validate(['newOwnerEmail' => ['required', 'email'], 'reason' => AdminAudit::reasonRule()]);

        $user = User::where('email', $d['newOwnerEmail'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['newOwnerEmail' => 'No existe ninguna usuaria con ese correo.']);
        }
        if ($user->suspended_at !== null) {
            throw ValidationException::withMessages(['newOwnerEmail' => 'Esa cuenta está suspendida.']);
        }

        $r = $this->actions->transfer($event, $user, $d['reason'], $request->user());

        return response()->json(['data' => [
            'newOwner' => ['id' => $r['newOwner']->id, 'name' => $r['newOwner']->name, 'email' => $r['newOwner']->email],
            'previousOwner' => $r['previousOwner'] ? ['id' => $r['previousOwner']->id, 'name' => $r['previousOwner']->name, 'role' => 'editor'] : null,
        ]]);
    }
}
