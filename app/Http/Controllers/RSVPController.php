<?php

namespace App\Http\Controllers;

use App\Models\MainGuest;
use App\Models\PartyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RSVPController extends Controller
{
    public function __construct(){}

    /**
     * Check the access code.
     *
     * @param mixed $accessCode The access code to check
     * @return JsonResponse Returns a JSON response indicating the result of the access code check
     */
    public function checkAccessCode(string $accessCode): JsonResponse
    {
        $mainGuest = MainGuest::query()
            ->with(['partyMembers'])
            ->where('access_code',  Str::upper($accessCode))
            ->first();

        if (!$mainGuest) {
            return response()->json(['data' => 'Invalid Access Code, please try again'], 404);
        }

        if ($mainGuest->partyMembers) {
            $mainGuest->partyMembers->each(function($member) {
                if ($member->confirmed !== 'no') {
                    $member->confirmed = 'yes';
                }
            });
        }

        $mainGuest->code_used_times = $mainGuest->code_used_times + 1;
        $mainGuest->save();

        /* if ($mainGuest->confirmed_date) {
            return response()->json(
                [
                    'data' => 'Access code already used, please contact us if you need to change your selection'
                ],
                422
            );
        } */

        return response()->json(['data' => $mainGuest]);
    }

    /**
     * Confirm the guest's attendance and update the main guest information.
     *
     * @param Request $request The HTTP request object containing the main guest data.
     *                        It should contain the following parameters:
     *                          - mainGuest.id (int) The ID of the main guest to be updated.
     *                          - mainGuest.confirmed (bool) Flag indicating whether the guest confirmed attendance.
     *                          - mainGuest.party_members (int) The number of party members accompanying the main guest.
     *
     * @return JsonResponse A JSON response containing a success message if the confirmation is successful,
     *                      or an error message with a status code if the main guest is invalid.
     */
    public function guestConfirm(Request $request): JsonResponse
    {
        $mainGuest = MainGuest::query()
            ->find($request->input('mainGuest.id'));

        if (!$mainGuest) {
            return response()->json(['message' => 'Invalid main guest'], 500);
        }

        $mainGuest->confirmed = $request->input('mainGuest.confirmed');
        $mainGuest->phone_confirmed = !$request->input('isAllNo');
        $mainGuest->confirmed_date = $request->input('isAllNo') ? null : now();

        if ($request->input('noMainGuestButMember') === true) {
            $mainGuest->extra_phone = $request->input('phoneConfirmed');
        } else {
            $mainGuest->extra_phone = null;
        }

        PartyMember::query()
            ->where('main_guest_id', $mainGuest->id)
            ->delete();

        if ($request->input('mainGuest.party_members')) {
            $partyMembers = $request->input('mainGuest.party_members');
            if (count($partyMembers)) {
                foreach($partyMembers as $member) {
                    PartyMember::query()->create([
                        'main_guest_id' => $mainGuest->id,
                        'name' => $member['name'],
                        'confirmed' => $member['confirmed'],
                    ]);
                }
            }
        }
        $mainGuest->save();

        return response()->json(['message' => 'Thanks for your confirmation!']);
    }

    /**
     * Reset the code for a main guest.
     *
     * @param Request $request The HTTP request object.
     * @return JsonResponse The JSON response containing the result of the code reset operation.
     * @throws ValidationException if the validation fails.
     */
    public function resetCode(Request $request): JsonResponse
    {
        $this->validate($request, [
            'mainGuest' => ['required', 'exists:main_guests,id']
        ]);

        $mainGuest = MainGuest::query()
            ->find($request->input('mainGuest'));

        $mainGuest->confirmed = 'unused';
        $mainGuest->confirmed_date = null;
        $mainGuest->save();

        PartyMember::query()
            ->where('main_guest_id', $mainGuest->id)
            ->update(['confirmed' => 'unused']);

        return response()->json(['message' => 'Code restarted successfully!']);
    }
}
