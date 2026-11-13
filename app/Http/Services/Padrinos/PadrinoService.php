<?php

namespace App\Http\Services\Padrinos;

use App\Models\Events;
use App\Models\Padrino;
use Illuminate\Support\Facades\DB;

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
     * @param  list<array{budgetItemId: int, amount: float|int|string}>  $commitments
     */
    public function setCommitments(Padrino $padrino, array $commitments): Padrino
    {
        DB::transaction(function () use ($padrino, $commitments) {
            $padrino->commitments()->delete();
            foreach ($commitments as $commitment) {
                $padrino->commitments()->create(['budget_item_id' => $commitment['budgetItemId'], 'amount' => $commitment['amount']]);
            }
        });

        return $padrino->unsetRelation('commitments')->refresh();
    }
}
