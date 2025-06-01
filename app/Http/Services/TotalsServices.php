<?php

namespace App\Http\Services;

use App\Models\MainGuest;
use App\Models\PartyMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class TotalsServices
{
    private int $mainGuestCount;

    private int $partyMembersCount;

    public function __construct()
    {
        $this->mainGuestCount = 0;
        $this->partyMembersCount = 0;
    }

    /**
     * Getting totals.
     */
    public function totals(): array
    {
        return [
            'totalGuests' => $this->getTotalGuests(),
            'mainGuests' => $this->mainGuestCount,
            'partyMembers' => $this->partyMembersCount,
            'totalConfirmed' => $this->getTotalConfirmed(),
            'totalUnConfirmed' => $this->getTotalUnConfirmed(),
        ];
    }

    /**
     * Get the total number of guests by summing the count of main guests and party members.
     *
     * @return int The total number of guests.
     */
    private function getTotalGuests(): int
    {
        $this->mainGuestCount = MainGuest::query()->count();

        $this->partyMembersCount = PartyMember::query()->count();

        return $this->mainGuestCount + $this->partyMembersCount;
    }

    /**
     * Get the total number of confirmed guests.
     *
     * @return int The total number of confirmed guests.
     */
    private function getTotalConfirmed(): int
    {
        $mainGuestConfirmed = MainGuest::query()
            ->whereNotNull('confirmed_date')
            ->where('confirmed', '!=', 'unused')
            ->count();

        $partyMembersConfirmed = PartyMember::query()
            ->where('confirmed', '!=', 'unused')
            ->count();

        Log::info('confirmed', ['main_guest' => $mainGuestConfirmed, 'party_members' => $partyMembersConfirmed]);

        return $mainGuestConfirmed + $partyMembersConfirmed;
    }

    /**
     * @return int.
     */
    private function getTotalUnConfirmed(): int
    {
        $mainGuestsUnConfirmed = MainGuest::query()
            ->whereNull('confirmed_date')
            ->where('confirmed', 'unused')
            ->count();

        $partyMembersUnconfirmed = PartyMember::query()
            ->where('confirmed', 'unused')
            ->count();

        return $mainGuestsUnConfirmed + $partyMembersUnconfirmed;
    }

    /**
     * Getting details.
     */
    public function details(string $type): Collection|array
    {
        return match ($type) {
            'totalGuest' => $this->getGuests(),
            'mainGuests' => $this->mainGuests(),
            'partyMembers' => $this->partyMembers(),
            'totalConfirmed' => $this->totalConfirmed(),
            default => $this->totalUnConfirmed(),
        };
    }

    /**
     * Get the guests with their party members.
     *
     * @return Collection|array The guests with their party members.
     */
    private function getGuests(): Collection|array
    {
        return MainGuest::query()->with(['partyMembers'])->get()->toArray();
    }

    /**
     * Get the main guests.
     *
     * @return Collection|array The main guests.
     */
    private function mainGuests(): Collection|array
    {
        return $this->getGuests();
    }

    /**
     * Retrieve an array of all party members with their corresponding main guest.
     *
     * @return array An array of party members with their corresponding main guest.
     */
    private function partyMembers(): array
    {
        return PartyMember::query()
            ->with(['mainGuest'])->get()->toArray();
    }

    /**
     * Get the total number of confirmed guests and their party members.
     *
     * @return Builder[]|Collection The collection of confirmed guests and their party members.
     */
    private function totalConfirmed(): array|Collection
    {
        // 1. Fetch all MainGuests with confirmed = "yes" and their PartyMembers
        return MainGuest::query()
            ->where('confirmed', '!=', 'unused')
            ->with(['partyMembers'])
            ->get();
    }

    /**
     * Get all MainGuests with unconfirmed status and their associated PartyMembers.
     *
     * @return Collection A collection of MainGuest models with associated PartyMembers.
     */
    private function totalUnConfirmed(): Collection
    {
        // 1. Fetch all MainGuests with confirmed = "yes" and their PartyMembers
        return MainGuest::query()
            ->where('confirmed', 'unused')
            ->with(['partyMembers'])
            ->get();
    }
}
