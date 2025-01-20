<?php

namespace App\Http\Services\ExcelServices;

use App\Models\MainGuest;

class TotalConfirmedExport extends TotalExportHandle
{
    /**
     * Initialize data for the application.
     *
     * @param mixed ...$args The arguments for the method.
     * @return void
     */
    public function initData(...$args): void
    {
        $mainGuests = MainGuest::query()
            ->where('confirmed', '!=','unused')
            ->with(['partyMembers'])
            ->get();

        $data = [];

        $headers = [
            'Name', 'Is Main', 'Confirmed', 'Phone Number'
        ];

        $data[] = $headers;

        $mainGuests->each(function($guest) use(&$data){
           $data[] = [
               "$guest->first_name $guest->last_name",
               "yes",
               $guest->confirmed,
               $guest->phone_number
           ];

           if ($guest->partyMembers && $guest->partyMembers->count()) {
               $guest->partyMembers->each(function($member) use(&$data) {
                   $data[] = [
                       $member->name,
                       "no",
                       $member->confirmed,
                       'N/A'
                   ];
               });
           }
        });

        $this->data = $data;
    }
}
