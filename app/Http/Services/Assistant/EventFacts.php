<?php

namespace App\Http\Services\Assistant;

use Carbon\CarbonImmutable;

/**
 * Everything the assistant needs to know about one event, already read (AssistantFactsLoader) so that the engine
 * and the detectors stay pure. Defaults are "nothing yet", which makes test fixtures short.
 */
final class EventFacts
{
    /**
     * @param  string  $eventType  `quinceanera` | `wedding` | any other slug (unsupported)
     * @param  CarbonImmutable|null  $startsAt  the event date as wall time in the event's own timezone
     * @param  CarbonImmutable  $createdAt  when the organizer created the event, in the same timezone
     * @param  array<string, string>  $moduleStates  module key => `active` | `locked`; a module that is absent is switched off
     * @param  list<string>  $vendorCategories  budget category slugs that have an expense with a vendor name
     * @param  list<string>  $locationTypes  `ceremony` | `reception` | `other` of the event's locations
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?CarbonImmutable $startsAt,
        public readonly CarbonImmutable $createdAt,
        public readonly array $moduleStates = [],
        public readonly ?float $budgetCap = null,
        public readonly array $vendorCategories = [],
        public readonly array $locationTypes = [],
        public readonly int $peopleCount = 0,
        public readonly int $mainGuests = 0,
        public readonly int $guestsWithPhone = 0,
        public readonly int $invitedGuests = 0,
        public readonly int $respondedGuests = 0,
        public readonly int $pendingGuests = 0,
        public readonly int $confirmedGuests = 0,
        public readonly int $seatedConfirmedGuests = 0,
        public readonly ?int $planMaxGuests = null,
        public readonly int $padrinos = 0,
        public readonly int $padrinosThanked = 0,
        public readonly bool $published = false,
        public readonly string $invitationStage = 'invitation',
        public readonly bool $dressCode = false,
        public readonly int $timelineMoments = 0,
        public readonly bool $hasWaltz = false,
        public readonly bool $invitationDesign = false,
        public readonly bool $rsvpDeadline = false,
        public readonly int $budgetItems = 0,
        public readonly int $paymentsPending = 0,
    ) {}
}
