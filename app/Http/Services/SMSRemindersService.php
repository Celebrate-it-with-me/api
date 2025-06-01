<?php

namespace App\Http\Services;

use App\Http\Resources\SmsReminderResource;
use App\Models\MainGuest;
use App\Models\SmsReminder;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SMSRemindersService
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get main guest name and phone confirmed.
     */
    public function getRecipients(): ?Collection
    {
        $mainGuests = MainGuest::query()->get();

        $mainGuests = $mainGuests?->filter(function ($guest) {
            return (bool) $this->getPhoneNumber($guest);
        })->map(function ($guest) {
            return [
                'name' => "$guest->first_name $guest->last_name",
                'phoneNumber' => $this->getPhoneNumber($guest),
            ];
        });

        $mainGuests->prepend(['name' => 'All', 'phoneNumber' => 'all']);

        return $mainGuests;
    }

    /**
     * Get the phone number of a guest.
     *
     * @param  MainGuest  $guest  The guest object.
     * @return string The phone number.
     */
    private function getPhoneNumber(MainGuest $guest): string
    {
        if ($guest->phone_confirmed) {
            if ($guest->extra_phone) {
                return $guest->extra_phone;
            }

            return $guest->phone_number;
        }

        return '';
    }

    /**
     * Getting SMS reminders with Pagination.
     */
    public function getRemindersWithPagination(): AnonymousResourceCollection
    {
        $perPage = 10;

        $smsReminders = SmsReminder::query();

        return SmsReminderResource::collection($smsReminders->paginate($perPage));
    }

    /**
     * @throws Exception
     */
    public function create(): Model|Builder|SmsReminder
    {
        $smsReminder = SmsReminder::query()->create([
            'recipients' => $this->request->input('recipients'),
            'message' => $this->request->input('message'),
            'send_date' => Carbon::parse($this->request->input('sendDate')),
        ]);

        if (! $smsReminder) {
            throw new Exception('Ups, something went wrong, please try again later!');
        }

        return $smsReminder;
    }

    /**
     * Update an SMS reminder.
     *
     * @param  SmsReminder  $smsReminder  The SMS reminder object to be updated.
     * @return SmsReminder The updated SMS reminder.
     */
    public function update(SmsReminder $smsReminder): SmsReminder
    {
        $smsReminder->recipients = $this->request->input('recipients');
        $smsReminder->message = $this->request->input('message');
        $smsReminder->send_date = $this->request->input('sendDate');

        $smsReminder->save();

        $smsReminder->refresh();

        return $smsReminder;
    }

    /**
     * Destroy an SMS reminder.
     *
     * @param  SmsReminder  $smsReminder  The SMS reminder to be destroyed.
     * @return SmsReminder The destroyed SMS reminder.
     */
    public function destroy(SmsReminder $smsReminder): SmsReminder
    {
        $smsReminderSaved = clone $smsReminder;

        $smsReminder->delete();

        return $smsReminderSaved;
    }
}
