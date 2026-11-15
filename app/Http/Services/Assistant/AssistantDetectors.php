<?php

namespace App\Http\Services\Assistant;

use App\Models\Events;

/**
 * Looks at the facts of an event and says whether a step is already done: `true`, `false`, or `null` when there is not
 * enough data to tell (which counts as not done). Pure: it never touches the database. The thresholds are heuristics
 * that live here, in one place, and are covered by the engine tests (contract, decision 6).
 */
final class AssistantDetectors
{
    /** Share of main guests with a phone number for `guests_contact`. */
    public const CONTACT_RATIO = 0.8;

    /** Share of main guests that got an invitation for `invitations_sent`. */
    public const SENT_RATIO = 0.5;

    /** Share of main guests that answered for `rsvp_responses`. */
    public const RESPONDED_RATIO = 0.8;

    /** Share of confirmed guests with a table for `seating`. */
    public const SEATED_RATIO = 0.9;

    /** People on the list for `guest_list`: this many, or half of the plan's guest limit if that is fewer. */
    public const LIST_PEOPLE = 20;

    public const LIST_PLAN_SHARE = 0.5;

    /** A list of this size or less is never "complete". */
    public const LIST_TOO_SMALL = 5;

    /** Moments in the day's schedule for `program`. */
    public const PROGRAM_MOMENTS = 4;

    public function evaluate(?string $detector, EventFacts $f): ?bool
    {
        if ($detector === null || $detector === '') {
            return null;
        }

        if (str_starts_with($detector, 'budget_vendor:')) {
            return $f->budgetItems === 0 && $f->vendorCategories === []
                ? null
                : in_array(substr($detector, strlen('budget_vendor:')), $f->vendorCategories, true);
        }

        return match ($detector) {
            'budget_cap' => $f->budgetCap !== null && $f->budgetCap > 0,
            'locations' => in_array('reception', $f->locationTypes, true),
            'guest_list' => $this->guestList($f),
            'guests_contact' => $this->ratio($f->guestsWithPhone, $f->mainGuests, self::CONTACT_RATIO),
            'padrinos' => $f->padrinos >= 1,
            'save_the_date' => $f->published && in_array($f->invitationStage, [Events::STAGE_SAVE_THE_DATE, Events::STAGE_INVITATION], true),
            'dress_code' => $f->dressCode,
            'program' => $f->timelineMoments >= self::PROGRAM_MOMENTS,
            'waltz' => $f->hasWaltz,
            'invitation_design' => $f->invitationDesign,
            'published' => $f->published && $f->invitationStage === Events::STAGE_INVITATION,
            'invitations_sent' => $this->ratio($f->invitedGuests, $f->mainGuests, self::SENT_RATIO),
            'rsvp_deadline' => $f->rsvpDeadline,
            'rsvp_responses' => $this->ratio($f->respondedGuests, $f->mainGuests, self::RESPONDED_RATIO),
            'seating' => $this->ratio($f->seatedConfirmedGuests, $f->confirmedGuests, self::SEATED_RATIO),
            'payments_due' => $f->budgetItems === 0 ? null : $f->paymentsPending === 0,
            'padrinos_thanks' => $f->padrinos === 0 ? null : $f->padrinosThanked >= 1,
            default => null,
        };
    }

    private function guestList(EventFacts $f): ?bool
    {
        if ($f->mainGuests < 1) {
            return null;
        }

        $needed = self::LIST_PEOPLE;
        if ($f->planMaxGuests !== null && $f->planMaxGuests > 0) {
            $needed = min($needed, (int) ceil($f->planMaxGuests * self::LIST_PLAN_SHARE));
        }

        return $f->peopleCount > self::LIST_TOO_SMALL && $f->peopleCount >= $needed;
    }

    private function ratio(int $part, int $whole, float $needed): ?bool
    {
        return $whole <= 0 ? null : ($part / $whole) >= $needed;
    }
}
