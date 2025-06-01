<?php

namespace App\Http\Services\ExcelServices;

use App\Models\PartyMember;

class PartyMembersExport extends TotalExportHandle
{
    /**
     * Initialize data for the application.
     *
     * @param  mixed  ...$args  The arguments for the method.
     */
    public function initData(...$args): void
    {
        $partyMembers = PartyMember::query()
            ->with(['mainGuest'])->get();

        $data = [];

        $headers = [
            'Name', 'Main Guest', 'Confirmed',
        ];

        $data[] = $headers;

        $partyMembers->each(function ($member) use (&$data) {
            $data[] = [
                $member->name,
                "{$member->mainGuest->first_name} {$member->mainGuest->last_name}",
                $member->confirmed,
            ];
        });

        $this->data = $data;
    }
}
