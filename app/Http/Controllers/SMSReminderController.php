<?php

namespace App\Http\Controllers;

use App\Http\Requests\SmsReminder\CreateSmsReminderRequest;
use App\Http\Requests\SmsReminder\UpdateSmsReminderRequest;
use App\Http\Resources\SmsReminderResource;
use App\Http\Services\SMSRemindersService;
use App\Models\SmsReminder;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SMSReminderController extends Controller
{
    private SMSRemindersService $SMSRemindersService;

    public function __construct(SMSRemindersService $SMSRemindersService)
    {
        $this->SMSRemindersService = $SMSRemindersService;
    }

    /**
     * Get recipients.
     */
    public function getRecipients(): JsonResponse
    {
        try {
            return response()->json(['recipients' => $this->SMSRemindersService->getRecipients()]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Sms Reminder index.
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        try {
            return $this->SMSRemindersService->getRemindersWithPagination();
        } catch (Exception $e) {
            return response()->json(['message' => 'Unable to get the user list'], 409);
        }
    }

    /**
     * Create a new sms reminder.
     */
    public function store(CreateSmsReminderRequest $request): SmsReminderResource|JsonResponse
    {
        try {
            return SmsReminderResource::make($this->SMSRemindersService->create());
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage() . $e->getFile() . $e->getLine()], 409);
        }
    }

    /**
     * Get Sms Reminder.
     */
    public function show(SmsReminder $smsReminder): SmsReminderResource|JsonResponse
    {
        try {
            return SmsReminderResource::make($smsReminder);
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }

    /**
     * Update sms reminder info.
     */
    public function update(UpdateSmsReminderRequest $request, SmsReminder $smsReminder): SmsReminderResource|JsonResponse
    {
        try {
            return SmsReminderResource::make($this->SMSRemindersService->update($smsReminder));
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }

    /**
     * Remove User from database.
     */
    public function destroy(SmsReminder $smsReminder): SmsReminderResource|JsonResponse
    {
        try {
            return SmsReminderResource::make($this->SMSRemindersService->destroy($smsReminder));
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }
}
