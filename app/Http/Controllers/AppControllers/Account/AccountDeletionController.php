<?php

namespace App\Http\Controllers\AppControllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Account\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Delete my account (docs/plans/account-deletion-contract.md). The user is always the authenticated one. */
class AccountDeletionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 3600;

    public function __construct(private readonly AccountDeletionService $deletion) {}

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'email' => $user->email,
            'hasPassword' => (bool) $user->has_password,
            'events' => $this->deletion->eventCounts($user),
            'archiveDays' => AccountDeletionService::ARCHIVE_DAYS,
            'pendingPurchases' => $this->deletion->pendingPurchases($user),
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // Every attempt counts, right or wrong: a stolen session cannot guess the password or hammer the endpoint.
        $key = 'account-delete:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json(['message' => __('errors.too_many_attempts'), 'code' => 'too_many_attempts'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);

        if ($this->deletion->isStaff($user)) {
            return response()->json(['message' => __('errors.account_deletion.staff_account'), 'code' => 'staff_account'], 403);
        }

        $errors = [];
        $email = $request->input('email');
        if (! is_string($email) || Str::lower(trim($email)) !== Str::lower($user->email)) {
            $errors['email'] = ['email_mismatch'];
        }
        if ($user->has_password) {
            $password = $request->input('current_password');
            if (! is_string($password) || $password === '' || ! Hash::check($password, $user->password)) {
                $errors['current_password'] = ['invalid_current_password'];
            }
        }
        if ($errors) {
            return response()->json(['message' => __('errors.account_deletion.invalid'), 'errors' => $errors], 422);
        }

        if ($this->deletion->pendingPurchases($user) > 0) {
            return response()->json(['message' => __('errors.account_deletion.pending_payment'), 'code' => 'pending_payment'], 409);
        }

        $token = $user->currentAccessToken();
        $result = $this->deletion->delete($user);
        RateLimiter::clear($key);

        // A cookie session (the SPA login) has no token to delete: close the session itself and the cookie with it.
        if (! $token instanceof PersonalAccessToken) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json(['data' => [
            'deletedAt' => $result['deletedAt']->toIso8601String(),
            'archivedEvents' => $result['archivedEvents'],
            'deletedEvents' => $result['deletedEvents'],
        ]]);
    }
}
