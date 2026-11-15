<?php

namespace App\Http\Services\Assistant;

use App\Http\Services\AppServices\EventModules;
use App\Models\Events;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads what the assistant needs to know about an event with a handful of aggregate queries (no N+1, no cache):
 * the event's type and plan, one query with every count, the vendor categories, the locations and the module states.
 */
class AssistantFactsLoader
{
    /** Payments due within this many days count as "pending soon" for `payments_due`. */
    public const PAYMENTS_HORIZON_DAYS = 30;

    public function __construct(private readonly EventModules $modules) {}

    public function load(Events $event, CarbonImmutable $now): EventFacts
    {
        $timezone = $this->timezone($event);
        $id = (int) $event->id;
        $today = $now->setTimezone($timezone)->startOfDay();

        $event->loadMissing(['eventType:id,slug', 'eventPlan:id,max_guests']);

        $counts = $this->counts($id, $today->addDays(self::PAYMENTS_HORIZON_DAYS)->toDateString());

        $vendors = DB::table('budget_items as bi')
            ->join('event_budgets as eb', 'eb.id', '=', 'bi.event_budget_id')
            ->join('budget_categories as bc', 'bc.id', '=', 'bi.category_id')
            ->where('eb.event_id', $id)
            ->whereNotNull('bc.slug')
            ->whereNotNull('bi.vendor_name')
            ->whereRaw("trim(bi.vendor_name) <> ''")
            ->distinct()
            ->pluck('bc.slug')
            ->all();

        $locationTypes = DB::table('event_locations')->where('event_id', $id)->distinct()->pluck('type')->all();

        $moduleStates = [];
        foreach ($this->modules->forEvent($event) as $module) {
            $moduleStates[$module['key']] = $module['state'];
        }

        $config = $event->template_config;

        return new EventFacts(
            eventType: (string) ($event->eventType?->slug ?? ''),
            startsAt: $event->start_date ? CarbonImmutable::parse($event->start_date->format('Y-m-d H:i:s'), $timezone) : null,
            createdAt: CarbonImmutable::parse($event->created_at ?? $now)->setTimezone($timezone),
            moduleStates: $moduleStates,
            budgetCap: $counts->budget_cap !== null ? (float) $counts->budget_cap : null,
            vendorCategories: array_values($vendors),
            locationTypes: array_values($locationTypes),
            peopleCount: (int) $counts->people,
            mainGuests: (int) $counts->main_guests,
            guestsWithPhone: (int) $counts->with_phone,
            invitedGuests: (int) $counts->invited,
            respondedGuests: (int) $counts->responded,
            pendingGuests: (int) $counts->pending,
            confirmedGuests: (int) $counts->confirmed,
            seatedConfirmedGuests: (int) $counts->seated,
            planMaxGuests: $event->eventPlan?->max_guests !== null ? (int) $event->eventPlan->max_guests : null,
            padrinos: (int) $counts->padrinos,
            padrinosThanked: (int) $counts->padrinos_thanked,
            published: $event->status === 'published',
            invitationStage: (string) ($event->invitation_stage ?? Events::STAGE_INVITATION),
            dressCode: (bool) $counts->dress_code,
            timelineMoments: (int) $counts->timeline_moments,
            hasWaltz: (bool) $counts->waltz,
            invitationDesign: is_array($config) && (! empty($config['sections']) || ! empty($config['theme'])),
            rsvpDeadline: (bool) $counts->rsvp_deadline,
            budgetItems: (int) $counts->budget_items,
            paymentsPending: (int) $counts->payments_pending,
        );
    }

    /** The event's own timezone; a bad value falls back to the column default instead of failing. */
    private function timezone(Events $event): string
    {
        $zone = (string) ($event->timezone ?: 'America/New_York');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'America/New_York';
    }

    /** Every scalar in one round trip. The event id is cast to int, the only other value is bound. */
    private function counts(int $id, string $paymentsUntil): object
    {
        $main = "event_id = {$id} and parent_id is null and deleted_at is null";

        $sql = <<<SQL
            select
              (select budget_cap from event_budgets where event_id = {$id} limit 1) as budget_cap,
              (select count(*) from guests where event_id = {$id} and deleted_at is null) as people,
              (select count(*) from guests where {$main}) as main_guests,
              (select count(*) from guests where {$main} and phone is not null and trim(phone) <> '') as with_phone,
              (select count(*) from guests where {$main} and rsvp_status <> 'pending') as responded,
              (select count(*) from guests where {$main} and rsvp_status = 'pending') as pending,
              (select count(*) from guests where event_id = {$id} and deleted_at is null and rsvp_status = 'attending') as confirmed,
              (select count(*) from guests g where g.event_id = {$id} and g.parent_id is null and g.deleted_at is null and (
                  exists (select 1 from guest_invitations gi where gi.guest_id = g.id)
                  or exists (select 1 from whatsapp_messages wm where wm.guest_id = g.id and wm.status in ('sent', 'delivered', 'read'))
              )) as invited,
              (select count(distinct ta.guest_id) from table_assignments ta join guests g on g.id = ta.guest_id
                 where g.event_id = {$id} and g.deleted_at is null and g.rsvp_status = 'attending') as seated,
              (select count(*) from padrinos where event_id = {$id}) as padrinos,
              (select count(*) from padrinos where event_id = {$id} and thanks_text is not null and trim(thanks_text) <> '') as padrinos_thanked,
              exists (select 1 from dress_codes where event_id = {$id} and dress_code_type is not null) as dress_code,
              (select count(*) from timelines where event_id = {$id}) as timeline_moments,
              exists (select 1 from timelines where event_id = {$id} and type = 'waltz') as waltz,
              exists (select 1 from rsvps where event_id = {$id} and confirmation_deadline is not null) as rsvp_deadline,
              (select count(*) from budget_items bi join event_budgets eb on eb.id = bi.event_budget_id where eb.event_id = {$id}) as budget_items,
              (select count(*) from budget_items bi join event_budgets eb on eb.id = bi.event_budget_id
                 where eb.event_id = {$id} and bi.due_date is not null and bi.due_date <= ?
                   and coalesce(bi.agreed_cost, bi.estimated_cost, 0) - coalesce((select sum(bp.amount) from budget_payments bp where bp.budget_item_id = bi.id), 0) > 0
              ) as payments_pending
            SQL;

        return DB::selectOne($sql, [$paymentsUntil]);
    }
}
