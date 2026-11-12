<?php

namespace App\Http\Services\Budget;

use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\BudgetPayment;
use App\Models\EventBudget;
use App\Models\Events;
use App\Observers\BudgetItemObserver;
use Illuminate\Support\Collection;

/**
 * The budget of an event. The math lives here, never in the screens: what each item owes, what has been paid,
 * what is left, and the same by category and for the whole event.
 */
class BudgetService
{
    public function __construct(private readonly BudgetCatalog $catalog) {}

    /** The event's budget; the first time, it is created with the starting categories for its kind of event. */
    public function budgetFor(Events $event): EventBudget
    {
        $budget = EventBudget::query()->where('event_id', $event->id)->first();

        if ($budget) {
            return $budget;
        }

        $budget = EventBudget::query()->create(['event_id' => $event->id, 'budget_cap' => null]);

        foreach ($this->catalog->names($event->eventType?->slug, $event->locale ?? 'es') as $order => $name) {
            $budget->categories()->create(['name' => $name, 'sort_order' => $order + 1, 'is_default' => true]);
        }

        return $budget;
    }

    /** @return Collection<int, BudgetItem> */
    public function items(EventBudget $budget): Collection
    {
        return $budget->items()->with('payments')->orderBy('due_date')->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    public function summary(Events $event, EventBudget $budget): array
    {
        $items = $this->items($budget);
        $cap = $budget->budget_cap !== null ? (float) $budget->budget_cap : null;
        $totals = $this->totals($items);

        $categories = $budget->categories()->get()->map(function (BudgetCategory $category) use ($items) {
            $mine = $items->where('category_id', $category->id);

            return ['id' => $category->id, 'name' => $category->name, 'sortOrder' => $category->sort_order, 'itemCount' => $mine->count(), 'totals' => $this->totals($mine)];
        })->values()->all();

        $loose = $items->whereNull('category_id');

        return [
            'id' => $budget->id,
            'currency' => $event->currency ?: 'USD',
            'cap' => $cap,
            'totals' => $totals + [
                'overCap' => $cap !== null && $totals['owed'] > $cap,
                'capLeft' => $cap !== null ? round($cap - $totals['owed'], 2) : null,
                'overdueCount' => $items->filter(fn (BudgetItem $i) => $i->status() === BudgetItem::OVERDUE)->count(),
            ],
            'categories' => $categories,
            'uncategorized' => ['itemCount' => $loose->count(), 'totals' => $this->totals($loose)],
        ];
    }

    /** @param Collection<int, BudgetItem> $items @return array{estimated: float, owed: float, paid: float, remaining: float} */
    public function totals(Collection $items): array
    {
        $owed = round($items->sum(fn (BudgetItem $i) => $i->owed()), 2);
        $paid = round($items->sum(fn (BudgetItem $i) => $i->paid()), 2);

        return [
            'estimated' => round($items->sum(fn (BudgetItem $i) => (float) $i->estimated_cost), 2),
            'owed' => $owed,
            'paid' => $paid,
            'remaining' => round(max(0, $owed - $paid), 2),
        ];
    }

    /** The shape the screens read. @return array<string, mixed> */
    public function row(BudgetItem $item): array
    {
        $item->loadMissing('payments.padrino');

        return [
            'id' => $item->id,
            'categoryId' => $item->category_id,
            'title' => $item->title,
            'description' => $item->description,
            'vendorName' => $item->vendor_name,
            'vendorPhone' => $item->vendor_phone,
            'estimatedCost' => (float) $item->estimated_cost,
            'agreedCost' => $item->agreed_cost !== null ? (float) $item->agreed_cost : null,
            'owed' => $item->owed(),
            'paid' => $item->paid(),
            'remaining' => $item->remaining(),
            'status' => $item->status(),
            'dueDate' => $item->due_date?->toDateString(),
            'payments' => $item->payments->map(fn (BudgetPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'paidOn' => $p->paid_on->toDateString(),
                'note' => $p->note,
                'padrinoId' => $p->padrino_id,
                'padrinoName' => $p->padrino?->name,
            ])->values()->all(),
        ];
    }

    /** Add a payment. When it settles the item the owner is told. */
    public function addPayment(BudgetItem $item, array $data): BudgetItem
    {
        $item->loadMissing('payments');
        $wasPaid = $item->isPaid();

        $item->payments()->create($data);
        $item->unsetRelation('payments')->load('payments');

        if (! $wasPaid && $item->isPaid()) {
            app(BudgetItemObserver::class)->paid($item);
        }

        return $item;
    }

    /** The budget as a spreadsheet: one row per item, in the event's language. @return list<list<string|float>> */
    public function exportRows(Events $event, EventBudget $budget): array
    {
        $english = ($event->locale ?? 'es') === 'en';
        $statuses = $english
            ? ['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid', 'overdue' => 'Overdue']
            : ['unpaid' => 'Sin pagar', 'partial' => 'Parcial', 'paid' => 'Pagado', 'overdue' => 'Vencido'];
        $names = $budget->categories()->pluck('name', 'id');

        $rows = [$english
            ? ['Category', 'Item', 'Vendor', 'Phone', 'Estimated', 'Agreed', 'Paid', 'Remaining', 'Status', 'Due date']
            : ['Categoría', 'Concepto', 'Proveedor', 'Teléfono', 'Estimado', 'Acordado', 'Pagado', 'Pendiente', 'Estado', 'Fecha límite']];

        foreach ($this->items($budget) as $item) {
            $rows[] = [
                $names[$item->category_id] ?? '',
                $item->title,
                (string) $item->vendor_name,
                (string) $item->vendor_phone,
                (float) $item->estimated_cost,
                $item->agreed_cost !== null ? (float) $item->agreed_cost : '',
                $item->paid(),
                $item->remaining(),
                $statuses[$item->status()],
                $item->due_date?->toDateString() ?? '',
            ];
        }

        return $rows;
    }
}
