<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanionServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Removes a GuestCompanion record from the database.
     *
     * @param GuestCompanion $guestCompanion The companion record to be removed.
     * @return bool Returns true if the deletion is successful, otherwise false.
     */
    public function removeCompanion(GuestCompanion $guestCompanion): bool
    {
        try {
            $guestCompanion->delete();
            return true;
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return false;
        }
    }
    
    /**
     * Creates a new guest companion associated with the given main guest.
     *
     * @param MainGuest $mainGuest The main guest to associate with the companion.
     * @return GuestCompanion The newly created guest companion.
     */
    public function createCompanion(MainGuest $mainGuest): GuestCompanion
    {
        return GuestCompanion::query()->create([
            'main_guest_id' => $mainGuest->id,
            'first_name' => $this->request->input('firstName'),
            'last_name' => $this->request->input('lastName'),
            'email' => $this->request->input('email'),
            'phone_number' => $this->request->input('phoneNumber'),
        ]);
    }
}
