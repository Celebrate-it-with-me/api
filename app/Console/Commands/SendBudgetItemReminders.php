<?php

namespace App\Console\Commands;

use App\Models\BudgetItem;
use App\Models\BudgetItemReminder;
use App\Models\User;
use App\Notifications\BudgetItemReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SendBudgetItemReminders extends Command
{
    public const THRESHOLD_DAYS = [7, 3, 1, 0];
    
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'budget-item:reminders
                             {--dry-run : Show in console items that need reminders, but do not send the notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send budget item reminders to organizer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $budgetItems = $this->getBudgetItemsNeedingReminders();
        
        if ($budgetItems->isEmpty()) {
            $this->info('No budget items need reminders');
            return self::SUCCESS;
        }

        $now = now()->startOfDay();

        $itemsByThreshold = [];
        foreach (self::THRESHOLD_DAYS as $threshold) {
            $itemsByThreshold[$threshold] = $budgetItems->filter(function ($item) use ($now, $threshold) {
                return $now->diffInDays($item->due_date->startOfDay()) == $threshold;
            });
        }
        
        $itemIds = $budgetItems->pluck('id')->toArray();
        $existingReminders = BudgetItemReminder::whereIn('budget_item_id', $itemIds)
            ->get()
            ->groupBy('budget_item_id')
            ->map(fn ($group) => $group->pluck('threshold_days')->toArray());

        foreach ($itemsByThreshold as $threshold => $items) {
            $itemsByThreshold[$threshold] = $items->filter(function ($item) use ($existingReminders, $threshold) {
                $sentThresholds = $existingReminders->get($item->id, []);
                return !in_array($threshold, $sentThresholds);
            });
        }

        if ($isDryRun) {
            $this->info('Dry run mode enabled. No notifications will be sent.');
            foreach ($itemsByThreshold as $threshold => $items) {
                $this->displayDryRunInfo($items, $threshold);
            }
            return self::SUCCESS;
        }

        $sentCount = 0;
        
        // Group items by event to consolidate notifications
        $itemsByEvent = [];
        foreach ($itemsByThreshold as $threshold => $items) {
            foreach ($items as $item) {
                $eventId = $item->budget?->event_id;
                if (!$eventId) continue;
                
                if (!isset($itemsByEvent[$eventId])) {
                    $itemsByEvent[$eventId] = [
                        'event' => $item->budget->event,
                        'items' => []
                    ];
                }
                
                $itemsByEvent[$eventId]['items'][] = [
                    'item' => $item,
                    'threshold' => $threshold
                ];
            }
        }

        foreach ($itemsByEvent as $eventId => $data) {
            $sentCount += $this->processConsolidatedNotifications($data['event'], $data['items']);
        }

        if ($sentCount === 0) {
            $this->info('No reminders needed to send.');
        } else {
            $this->info("Sent notifications for $sentCount events.");
        }

        return self::SUCCESS;
    }

    protected function displayDryRunInfo(Collection $items, int $threshold): void
    {
        foreach ($items as $item) {
            $this->line("Reminder needed for item ID {$item->id}: \"{$item->title}\" (Threshold: {$threshold} days)");
        }
    }

    protected function processConsolidatedNotifications($event, array $budgetItemsData): int
    {
        if (empty($budgetItemsData)) return 0;

        $ownerUserId = optional($event->userRoles->first())->user_id ?? 0;
        if (!$ownerUserId) return 0;

        $user = User::find($ownerUserId);
        if (!$user) return 0;

        $user->notify(new BudgetItemReminderNotification($budgetItemsData, $event));

        foreach ($budgetItemsData as $data) {
            BudgetItemReminder::query()->create([
                'budget_item_id' => $data['item']->id,
                'user_id' => $user->id,
                'threshold_days' => $data['threshold'],
                'sent_at' => now(),
            ]);
        }

        return 1;
    }

    /**
     * Retrieve a collection of budget items that require reminders.
     *
     * The method queries the 'BudgetItem' model to find items that are unpaid and have
     * a due date within a defined threshold.
     *
     * @return Collection
     */
    private function getBudgetItemsNeedingReminders(): Collection
    {
        return BudgetItem::query()
            ->with(['budget.event.userRoles' => function ($query) {
                $query->whereHas('role', function ($q) {
                    $q->where('name', 'owner');
                });
            }])
            ->where('is_paid', 0)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays(max(self::THRESHOLD_DAYS))->endOfDay())
            ->get();
    }
    
}
