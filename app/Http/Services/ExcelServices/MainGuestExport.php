<?php

namespace App\Http\Services\ExcelServices;

use App\Models\MainGuest;

class MainGuestExport extends TotalExportHandle
{
    /**
     * Initialize data for the application.
     *
     * @param mixed ...$args The arguments for the method.
     * @return void
     */
    public function initData(...$args): void
    {
        $mainGuests = MainGuest::query()->get();
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
        });

        $this->data = $data;
    }
}
