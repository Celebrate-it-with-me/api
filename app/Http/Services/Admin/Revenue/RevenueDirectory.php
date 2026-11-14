<?php

namespace App\Http\Services\Admin\Revenue;

use App\Http\Services\Payments\PurchaseService;
use App\Models\Coupon;
use App\Models\EventPurchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of the global revenue view (docs/plans/admin-operations.md, A8): purchases with filters and a summary of the
 * filtered set, one purchase in detail, and the current price. Money is always in cents of the purchase currency.
 */
class RevenueDirectory
{
    public function __construct(private readonly PurchaseService $purchases) {}

    /** @param array<string, mixed> $f validated filters from PurchaseIndexRequest */
    public function list(array $f): array
    {
        $query = EventPurchase::query()->with([
            'event' => fn ($q) => $q->withTrashed()->select('id', 'event_name', 'status'),
            'coupon:id,code',
            'buyer:id,name,email',
        ]);
        $this->applyFilters($query, $f);

        $summary = $this->summary(clone $query);

        $page = $query->orderByDesc('event_purchases.created_at')->orderByDesc('event_purchases.id')
            ->paginate((int) ($f['perPage'] ?? 15), ['event_purchases.*'], 'page', (int) ($f['page'] ?? 1));

        return [
            'data' => $page->getCollection()->map(fn (EventPurchase $p) => $this->row($p))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'summary' => $summary,
        ];
    }

    public function detail(EventPurchase $purchase): array
    {
        $purchase->load([
            'event' => fn ($q) => $q->withTrashed()->select('id', 'event_name', 'status', 'custom_url_slug'),
            'coupon', 'buyer:id,name,email', 'plan:id,slug,name', 'creator:id,name,email', 'invoices:id,purchase_id,number,status',
        ]);

        $timeline = array_values(array_filter([
            ['type' => 'created', 'at' => $purchase->created_at?->toIso8601String(), 'by' => null],
            $purchase->paid_at ? ['type' => 'paid', 'at' => $purchase->paid_at->toIso8601String(), 'by' => $purchase->creator ? $this->person($purchase->creator) : null] : null,
            $purchase->refunded_at ? ['type' => 'refunded', 'at' => $purchase->refunded_at->toIso8601String(), 'by' => null] : null,
        ]));

        return $this->row($purchase) + [
            'plan' => $purchase->plan ? ['slug' => $purchase->plan->slug, 'name' => $purchase->plan->name] : null,
            'eventSlug' => $purchase->event?->custom_url_slug,
            'coupon' => $purchase->coupon ? [
                'id' => $purchase->coupon->id, 'code' => $purchase->coupon->code, 'kind' => $purchase->coupon->kind,
                'value' => $purchase->coupon->value, 'isFounder' => $purchase->coupon->is_founder,
            ] : null,
            'discountCents' => max(0, $purchase->list_price_cents - $purchase->amount_cents),
            'registeredBy' => $purchase->gateway === 'manual' && $purchase->creator ? $this->person($purchase->creator) : null,
            'notes' => $purchase->notes,
            'receiptUrl' => $purchase->receipt_url,
            // Gateway identifiers are never shown whole.
            'gatewayRef' => $this->mask($purchase->gateway_payment_id ?? $purchase->gateway_session_id),
            'timeline' => $timeline,
            'invoices' => $purchase->invoices->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status])->values(),
        ];
    }

    public function pricing(): array
    {
        $plan = $this->purchases->fullPlan();

        return [
            'plan' => [
                'slug' => $plan->slug, 'name' => $plan->name, 'priceCents' => $plan->price_cents,
                'currency' => $plan->currency, 'maxGuests' => $plan->max_guests,
            ],
            'purchases' => [
                'total' => EventPurchase::query()->count(),
                'paid' => EventPurchase::query()->where('status', 'paid')->count(),
            ],
            'coupons' => [
                'total' => Coupon::query()->count(),
                'active' => Coupon::query()->where('is_active', true)->count(),
            ],
        ];
    }

    private function row(EventPurchase $p): array
    {
        return [
            'id' => $p->id,
            'event' => $p->event ? ['id' => $p->event->id, 'name' => $p->event->event_name, 'status' => $p->event->status] : null,
            'buyer' => $p->buyer ? $this->person($p->buyer) : null,
            'couponCode' => $p->coupon?->code,
            'gateway' => $p->gateway,
            'status' => $p->status,
            'isFounder' => $p->is_founder,
            'listPriceCents' => $p->list_price_cents,
            'amountCents' => $p->amount_cents,
            'currency' => $p->currency,
            'paidAt' => $p->paid_at?->toIso8601String(),
            'refundedAt' => $p->refunded_at?->toIso8601String(),
            'createdAt' => $p->created_at?->toIso8601String(),
            // A refund is full and only while the invitation is not published (PurchaseService::refund).
            'canRefund' => $p->status === 'paid' && $p->event?->status !== 'published',
        ];
    }

    private function person($user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    private function mask(?string $ref): ?string
    {
        return $ref === null || $ref === '' ? null : '…' . substr($ref, -4);
    }

    private function summary(Builder $query): array
    {
        $row = $query->reorder()->toBase()->selectRaw(
            'COUNT(*) as total, '
            . "COALESCE(SUM(CASE WHEN event_purchases.status = 'paid' THEN event_purchases.amount_cents END), 0) as revenue, "
            . "COUNT(CASE WHEN event_purchases.status = 'paid' THEN 1 END) as paid_count, "
            . "COALESCE(SUM(CASE WHEN event_purchases.status = 'refunded' THEN event_purchases.amount_cents END), 0) as refunded, "
            . "COUNT(CASE WHEN event_purchases.status = 'refunded' THEN 1 END) as refunded_count"
        )->first();

        return [
            'count' => (int) $row->total,
            'paidCount' => (int) $row->paid_count,
            'revenueCents' => (int) $row->revenue,
            'refundedCount' => (int) $row->refunded_count,
            'refundedCents' => (int) $row->refunded,
        ];
    }

    private function applyFilters(Builder $query, array $f): void
    {
        if (filled($f['search'] ?? null)) {
            $like = '%' . strtolower(addcslashes($f['search'], '\\%_')) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereHas('event', fn ($e) => $e->withTrashed()->whereRaw("lower(events.event_name) like ? escape '\\'", [$like]))
                    ->orWhereHas('buyer', fn ($u) => $u->whereRaw("lower(users.email) like ? escape '\\'", [$like])
                        ->orWhereRaw("lower(users.name) like ? escape '\\'", [$like]));
            });
        }
        if (filled($f['status'] ?? null)) {
            $query->where('event_purchases.status', $f['status']);
        }
        if (filled($f['gateway'] ?? null)) {
            $query->where('event_purchases.gateway', $f['gateway']);
        }
        if (array_key_exists('founder', $f) && $f['founder'] !== null) {
            $query->where('event_purchases.is_founder', (bool) $f['founder']);
        }
        if (filled($f['coupon'] ?? null)) {
            $query->whereHas('coupon', fn ($c) => $c->where('code', strtoupper(trim($f['coupon']))));
        }
        $this->range($query, 'event_purchases.paid_at', $f['paidFrom'] ?? null, $f['paidTo'] ?? null);
        $this->range($query, 'event_purchases.created_at', $f['createdFrom'] ?? null, $f['createdTo'] ?? null);
    }

    private function range(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->where($column, '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to) {
            $query->where($column, '<=', Carbon::parse($to)->endOfDay());
        }
    }
}
