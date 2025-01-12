<?php

namespace App\Console\Commands;

use App\Models\MainGuest;
use App\Models\PartyMember;
use Illuminate\Console\Command;

class MigratePartyMembers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cwm:migrate-party-members';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $mainGuests = MainGuest::query()->get();

        $mainGuests?->each(function ($guest) {
            $partyMembers = is_array($guest->party_members)
                ? $guest->party_members
                : json_decode($guest->party_members, true);

            if (count($partyMembers)) {
                foreach ($partyMembers as $member) {
                    PartyMember::query()->create([
                        'main_guest_id' => $guest->id,
                        'name' => $member['name'],
                        'confirmed' => 'unused',
                    ]);
                }
            }
        });
    }
}
