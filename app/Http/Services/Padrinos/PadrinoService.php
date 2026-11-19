<?php

namespace App\Http\Services\Padrinos;

use App\Models\BudgetItem;
use App\Models\BudgetPayment;
use App\Models\Events;
use App\Models\Padrino;
use App\Models\PadrinoCommitment;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Padrinos of an event. Everything about money comes from the budget: a padrino's commitments are amounts on
 * budget items, and what they paid is the sum of the payments marked as theirs. Nothing is stored twice.
 */
class PadrinoService
{
    /** @return list<array<string, mixed>> */
    public function list(Events $event): array
    {
        return $event->padrinos()
            ->with(['commitments.item', 'payments'])
            ->get()
            ->map(fn (Padrino $padrino) => $this->row($padrino))
            ->values()
            ->all();
    }

    /** @return array{committed: float, paid: float, remaining: float, count: int} */
    public function totals(array $rows): array
    {
        $committed = round(array_sum(array_column($rows, 'committed')), 2);
        $paid = round(array_sum(array_column($rows, 'paid')), 2);

        return ['committed' => $committed, 'paid' => $paid, 'remaining' => round(max(0, $committed - $paid), 2), 'count' => count($rows)];
    }

    /** @return array<string, mixed> */
    public function row(Padrino $padrino): array
    {
        $padrino->loadMissing(['commitments.item', 'payments']);

        $committed = round((float) $padrino->commitments->sum('amount'), 2);
        $paid = round((float) $padrino->payments->sum('amount'), 2);

        return [
            'id' => $padrino->id,
            'guestId' => $padrino->guest_id,
            'name' => $padrino->name,
            'phone' => $padrino->phone,
            'relationship' => $padrino->relationship,
            'status' => $padrino->status,
            'publicThanks' => $padrino->public_thanks,
            'thanksText' => $padrino->thanks_text,
            'commitments' => $padrino->commitments->map(fn ($c) => [
                'budgetItemId' => $c->budget_item_id,
                'itemTitle' => $c->item?->title,
                'amount' => (float) $c->amount,
            ])->values()->all(),
            'committed' => $committed,
            'paid' => $paid,
            'remaining' => max(0, round($committed - $paid, 2)),
            'progress' => $this->progress($committed, $paid),
        ];
    }

    /** none (nothing promised yet), unpaid, partial, paid. */
    private function progress(float $committed, float $paid): string
    {
        if ($committed <= 0) {
            return 'none';
        }

        return $paid <= 0 ? 'unpaid' : ($paid >= $committed ? 'paid' : 'partial');
    }

    public function create(Events $event, array $data): Padrino
    {
        return $event->padrinos()->create($data + ['sort_order' => (int) $event->padrinos()->max('sort_order') + 1]);
    }

    /**
     * Replace what the padrino promised to cover.
     *
     * An item's cost is `BudgetItem::owed()` (agreed price, or the estimate until there is one), the same the Budget screen shows;
     * `paid` is the sum of its payments and `remaining` is owed minus paid, never below zero.
     * When a commitment is new or grows and the item already has payments nobody owns (the family paid), the caller must say
     * what that means with `resolution`: `attribute_payments` (those payments become this padrino's) or `additional` (a promise
     * for the part still unpaid, at most `remaining`). Without it the whole operation is refused with a 409 and nothing changes.
     *
     * @param  list<array{budgetItemId: int, amount: float|int|string, resolution?: string|null}>  $commitments
     * @return array{padrino: Padrino, warnings: list<array{code: string, budgetItemId: int}>}
     */
    public function setCommitments(Padrino $padrino, array $commitments): array
    {
        $warnings = DB::transaction(function () use ($padrino, $commitments) {
            $previous = $padrino->commitments()->pluck('amount', 'budget_item_id')->map(fn ($a) => (float) $a);
            $items = BudgetItem::query()->with('payments')->whereIn('id', array_column($commitments, 'budgetItemId'))->get()->keyBy('id');

            $conflicts = [];
            $attribute = [];
            foreach ($commitments as $commitment) {
                $item = $items[$commitment['budgetItemId']];
                $before = (float) ($previous[$item->id] ?? 0);
                $amount = (float) $commitment['amount'];
                $family = $item->payments->whereNull('padrino_id');
                if ($amount <= $before || $family->sum('amount') <= 0) {
                    continue;
                }

                $resolution = $commitment['resolution'] ?? null;
                if ($resolution === null) {
                    $conflicts[] = $this->conflict($item, $family);
                } elseif ($resolution === 'additional') {
                    $this->guardRemaining($item, round($amount - $before, 2));
                } else {
                    $attribute[] = $item;
                }
            }

            if ($conflicts !== []) {
                throw new HttpResponseException(response()->json([
                    'code' => 'commitment_item_paid',
                    'message' => __('errors.padrino.commitment_item_paid'),
                    'conflicts' => $conflicts,
                ], 409));
            }

            foreach ($attribute as $item) {
                $this->attributePayments($item, $padrino);
            }

            $padrino->commitments()->delete();
            foreach ($commitments as $commitment) {
                $padrino->commitments()->create(['budget_item_id' => $commitment['budgetItemId'], 'amount' => $commitment['amount']]);
            }

            return $this->warnings($items);
        });

        return ['padrino' => $padrino->unsetRelation('commitments')->refresh(), 'warnings' => $warnings];
    }

    /** @return array<string, mixed> */
    private function conflict(BudgetItem $item, Collection $family): array
    {
        return [
            'budgetItemId' => $item->id,
            'itemName' => $item->title,
            'cost' => $item->owed(),
            'paid' => $item->paid(),
            'remaining' => $item->remaining(),
            'unattributedPayments' => $family->map(fn (BudgetPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'paidOn' => $p->paid_on->toDateString(),
                'note' => $p->note,
            ])->values()->all(),
        ];
    }

    private function guardRemaining(BudgetItem $item, float $added): void
    {
        if ($added > $item->remaining()) {
            throw new HttpResponseException(response()->json([
                'code' => 'commitment_exceeds_remaining',
                'message' => __('errors.padrino.commitment_exceeds_remaining'),
                'budgetItemId' => $item->id,
                'remaining' => $item->remaining(),
            ], 422));
        }
    }

    /** The payments nobody owned become the padrino's, keeping date and amount; the note records it and keeps the original. */
    private function attributePayments(BudgetItem $item, Padrino $padrino): void
    {
        $mark = 'Attributed to padrino ' . Str::limit($padrino->name, 40, '');
        foreach ($item->payments->whereNull('padrino_id') as $payment) {
            $note = $payment->note ? $payment->note . ' | ' . $mark : $mark;
            $payment->update(['padrino_id' => $padrino->id, 'note' => mb_substr($note, 0, 255)]);
        }
    }

    /** @return list<array{code: string, budgetItemId: int}> */
    private function warnings(Collection $items): array
    {
        $totals = PadrinoCommitment::query()->whereIn('budget_item_id', $items->keys())->selectRaw('budget_item_id, sum(amount) as total')->groupBy('budget_item_id')->pluck('total', 'budget_item_id');

        return $items->filter(fn (BudgetItem $item) => (float) ($totals[$item->id] ?? 0) > $item->owed())
            ->map(fn (BudgetItem $item) => ['code' => 'commitments_exceed_cost', 'budgetItemId' => $item->id])->values()->all();
    }
}
