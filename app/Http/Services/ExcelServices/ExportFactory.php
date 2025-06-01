<?php

namespace App\Http\Services\ExcelServices;

class ExportFactory
{
    public static function make(string $type): TotalExportHandle
    {
        return match ($type) {
            'totalGuest' => new TotalGuestsExport,
            'mainGuests' => new MainGuestExport,
            'partyMembers' => new PartyMembersExport,
            'totalConfirmed' => new TotalConfirmedExport,
            default => new TotalUnConfirmedExport,
        };
    }
}
