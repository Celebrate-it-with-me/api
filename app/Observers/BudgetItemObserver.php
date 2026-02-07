<?php

namespace App\Observers;

use App\Events\EventNotificationEvent;
use App\Models\BudgetItem;
use App\Support\Notifications\NotificationKeys;
use Illuminate\Support\Facades\Auth;

class BudgetItemObserver
{
    public function created(BudgetItem $item): void
    {
        $this->dispatch($item, NotificationKeys::BUDGET_ITEM_CREATED);
    }

    public function updated(BudgetItem $item): void
    {
        if ($item->wasChanged('is_paid') && $item->is_paid) {
            $this->dispatch($item, NotificationKeys::BUDGET_ITEM_PAID);
        }
    }

    protected function dispatch(BudgetItem $item, string $key): void
    {
        $budget = $item->budget;
        if (!$budget) return;

        $event = $budget->events; // Note: model has events() relationship
        if (!$event) return;

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id ?? 0;
        if ($ownerUserId <= 0) return;

        $user = Auth::user();
        $actor = [
            'type' => 'user',
            'id' => $user?->id ?? 0,
            'name' => $user?->name ?? 'System',
            'avatar_url' => $user?->avatar_url ?? null,
        ];

        EventNotificationEvent::dispatch(
            $key,
            (int) $event->id,
            (int) $item->id,
            $actor,
            (int) $ownerUserId,
            ['item_title' => $item->title]
        );
    }
}
