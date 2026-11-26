<?php

namespace App\Http\Controllers\AppControllers\Budget;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\Budget\BudgetCategoryRequest;
use App\Http\Requests\app\Budget\BudgetItemRequest;
use App\Http\Requests\app\Budget\BudgetPaymentRequest;
use App\Http\Requests\app\Budget\UpdateBudgetRequest;
use App\Http\Services\Budget\BudgetService;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\BudgetPayment;
use App\Models\EventBudget;
use App\Models\Events;
use App\Support\CsvCells;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The budget of an event: summary, categories, items and their payments. Access to the event is checked by
 * `event.access`; the pieces below are also checked to belong to this event's budget (404 otherwise).
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function show(Request $request, Events $event): JsonResponse
    {
        $budget = $this->readable($request, $event);

        return response()->json(['data' => $budget ? $this->budgets->summary($event, $budget) : $this->budgets->draftSummary($event)]);
    }

    public function update(UpdateBudgetRequest $request, Events $event): JsonResponse
    {
        $budget = $this->budgets->budgetFor($event);
        $budget->update(['budget_cap' => $request->validated('cap')]);

        return response()->json(['data' => $this->budgets->summary($event, $budget->refresh())]);
    }

    // ── Categories ───────────────────────────────────────────

    public function storeCategory(BudgetCategoryRequest $request, Events $event): JsonResponse
    {
        $budget = $this->budgets->budgetFor($event);
        $budget->categories()->create(['name' => trim($request->validated('name')), 'sort_order' => (int) $budget->categories()->max('sort_order') + 1]);

        return response()->json(['data' => $this->budgets->summary($event, $budget)], 201);
    }

    public function updateCategory(BudgetCategoryRequest $request, Events $event, BudgetCategory $category): JsonResponse
    {
        $budget = $this->budgets->budgetFor($event);
        $this->own($budget->id === $category->event_budget_id);
        $category->update(['name' => trim($request->validated('name'))]);

        return response()->json(['data' => $this->budgets->summary($event, $budget)]);
    }

    /** A category with items cannot be removed: move or delete them first. */
    public function destroyCategory(Events $event, BudgetCategory $category): JsonResponse
    {
        $budget = $this->budgets->budgetFor($event);
        $this->own($budget->id === $category->event_budget_id);

        if ($category->items()->exists()) {
            return response()->json(['message' => __('errors.budget.category_not_empty'), 'errors' => ['category' => ['not_empty']]], 422);
        }

        $category->delete();

        return response()->json(['data' => $this->budgets->summary($event, $budget)]);
    }

    // ── Items ────────────────────────────────────────────────

    public function items(Request $request, Events $event): JsonResponse
    {
        $budget = $this->readable($request, $event);

        return response()->json(['data' => $budget ? $this->budgets->items($budget)->map(fn (BudgetItem $i) => $this->budgets->row($i))->values() : []]);
    }

    public function storeItem(BudgetItemRequest $request, Events $event): JsonResponse
    {
        $budget = $this->budgets->budgetFor($event);
        $item = $budget->items()->create($request->item());

        return response()->json(['data' => $this->budgets->row($item)], 201);
    }

    public function updateItem(BudgetItemRequest $request, Events $event, BudgetItem $item): JsonResponse
    {
        $this->own($this->budgets->budgetFor($event)->id === $item->event_budget_id);
        $item->update($request->item());

        return response()->json(['data' => $this->budgets->row($item->refresh())]);
    }

    public function destroyItem(Events $event, BudgetItem $item): JsonResponse
    {
        $this->own($this->budgets->budgetFor($event)->id === $item->event_budget_id);
        $item->delete();

        return response()->json(['message' => 'Item removed.']);
    }

    // ── Payments ─────────────────────────────────────────────

    public function storePayment(BudgetPaymentRequest $request, Events $event, BudgetItem $item): JsonResponse
    {
        $this->own($this->budgets->budgetFor($event)->id === $item->event_budget_id);

        return response()->json(['data' => $this->budgets->row($this->budgets->addPayment($item, $request->payment()))], 201);
    }

    public function updatePayment(BudgetPaymentRequest $request, Events $event, BudgetItem $item, BudgetPayment $payment): JsonResponse
    {
        $this->own($this->budgets->budgetFor($event)->id === $item->event_budget_id && $payment->budget_item_id === $item->id);
        $payment->update($request->payment());

        return response()->json(['data' => $this->budgets->row($item->refresh())]);
    }

    public function destroyPayment(Events $event, BudgetItem $item, BudgetPayment $payment): JsonResponse
    {
        $this->own($this->budgets->budgetFor($event)->id === $item->event_budget_id && $payment->budget_item_id === $item->id);
        $payment->delete();

        return response()->json(['data' => $this->budgets->row($item->refresh())]);
    }

    // ── Export ───────────────────────────────────────────────

    /** The budget as a CSV file (opens in Excel and Google Sheets). */
    public function export(Request $request, Events $event): StreamedResponse|Response
    {
        $budget = $this->readable($request, $event);
        $rows = $this->budgets->exportRows($event, $budget);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the accents
            foreach ($rows as $row) {
                fputcsv($out, CsvCells::row($row));
            }
            fclose($out);
        }, ($event->custom_url_slug ?: 'budget') . '-budget.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The budget for a read. Owners and editors create it on first read (with its starting categories); a member
     * who cannot edit (viewer) never writes on a GET, so before anyone with edit rights opens it they get null and
     * the screens answer with the empty shape.
     */
    private function readable(Request $request, Events $event): ?EventBudget
    {
        return in_array('edit_event', $request->user()->getEventPermissions($event), true)
            ? $this->budgets->budgetFor($event)
            : $this->budgets->existingFor($event);
    }

    private function own(bool $belongs): void
    {
        abort_unless($belongs, 404);
    }
}
