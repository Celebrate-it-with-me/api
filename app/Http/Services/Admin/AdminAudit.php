<?php

namespace App\Http\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the staff audit log (docs/plans/admin-brief.md, A1). Every write the panel does goes through here, with
 * who did it (from the request), to what, and why.
 */
class AdminAudit
{
    /** Subjects are stored as a short alias, not a class name. */
    private const ALIASES = [
        Events::class => 'event',
        User::class => 'user',
        EventPurchase::class => 'purchase',
        Coupon::class => 'coupon',
        EventPlan::class => 'plan',
        Module::class => 'module',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  Model|array{0: string, 1: int|null}|null  $subject  a model, or [alias, id]
     * @param  array{event_id?: int|null, user_id?: int|null, reason?: string|null, before?: array|null, after?: array|null, actor?: User|null}  $data
     */
    public function record(string $action, Model|array|null $subject = null, array $data = []): AdminAuditLog
    {
        [$type, $id] = match (true) {
            $subject instanceof Model => [self::ALIASES[$subject::class] ?? class_basename($subject), $subject->getKey()],
            is_array($subject) => [$subject[0], $subject[1] ?? null],
            default => [null, null],
        };

        $actor = $data['actor'] ?? $this->request->user();

        return AdminAuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'event_id' => $data['event_id'] ?? ($subject instanceof Events ? $subject->id : null),
            'user_id' => $data['user_id'] ?? ($subject instanceof User ? $subject->id : null),
            'reason' => $data['reason'] ?? null,
            'before' => $data['before'] ?? null,
            'after' => $data['after'] ?? null,
            'impersonated_by' => $this->request->attributes->get('impersonated_by'),
            'ip' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255) ?: null,
        ]);
    }

    /** Validation rule for the reason every sensitive action must carry. */
    public static function reasonRule(): array
    {
        return ['required', 'string', 'min:5', 'max:500'];
    }
}
