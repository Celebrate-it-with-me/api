<?php

namespace App\Http\Controllers;

use App\Events\ResetPasswordEvent;
use App\Events\UserLoggedInEvent;
use App\Events\UserLoggedOutEvent;
use App\Events\UserRegistered;
use App\Http\Requests\app\ForgotPasswordRequest;
use App\Http\Requests\app\ResetPasswordRequest;
use App\Http\Requests\Auth\AppLoginRequest;
use App\Http\Requests\Auth\AppRegisterRequest;
use App\Http\Requests\Auth\AdminTwoFactorLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Services\Admin\Account\AdminLoginService;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Middleware\EnsureNotSuspended;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * Step one of the admin sign-in. Without two-factor it answers the plain token (as always); with it, a JSON
     * `{twoFactorRequired: true, challenge}` that `adminTwoFactorLogin` trades for the token.
     *
     * @throws ValidationException
     */
    public function adminLogin(LoginRequest $request, AdminLoginService $login): JsonResponse|string
    {
        $result = $login->attempt($request->input('email'), $request->input('password'), $request->input('device'));

        return is_array($result) ? response()->json($result) : $result;
    }

    /**
     * Step two: the challenge plus a TOTP code or a recovery code gives the token.
     *
     * @throws ValidationException
     */
    public function adminTwoFactorLogin(AdminTwoFactorLoginRequest $request, AdminLoginService $login): string
    {
        return $login->complete($request->input('challenge'), $request->input('code'), $request->input('recoveryCode'), $request->input('device'));
    }

    /**
     * Register a new user based on the provided request data.
     *
     * @param AppRegisterRequest $request The request object containing user data to be validated and registered.
     *
     * @return JsonResponse A JSON response containing a message and the registered user data with HTTP status code 201.
     * @throws ConnectionException
     */
    public function appRegister(AppRegisterRequest $request): JsonResponse
    {
        if (config('services.hcaptcha.enabled')) {
            $token = $request->input('hcaptcha_token');

            if (!$token || !$this->verifyHCaptcha($token) ) {
                return response()->json(['message' => __('errors.auth.captcha_invalid')], 422);
            }
        }

        $user = User::query()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Assign the appUser role to give access to frontend routes
        $user->assignRole('owner');

        event(new UserRegistered($user));

        return response()->json([
           'message' => 'User registered successfully!',
           'user' => $user,
        ], 201);
    }

    /**
     * Confirm the user's email address.
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function confirmEmail(Request $request, User $user): JsonResponse
    {
        if (!$request->hasValidSignature() || !$request->user) {
            return response()->json(['message' => __('errors.auth.invalid_signature')], 401);
        }

        $user = User::query()->find($request->user);

        if (!$user || !$user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();
        }

        return response()->json(['message' => 'Email verified successfully!']);
    }

    /**
     * Forgot Password functionality.
     * @param ForgotPasswordRequest $request
     * @return JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        // At most 3 emails per hour to one address: the answer stays the same so it does not reveal anything.
        $limitKey = 'app-forgot:' . Str::lower((string) $request->input('email'));
        if (!$user || RateLimiter::tooManyAttempts($limitKey, 3)) {
            return response()->json(['message' => 'Password reset link sent successfully!']);
        }
        RateLimiter::hit($limitKey, 3600);

        ResetPasswordEvent::dispatch($user);

        return response()->json(['message' => 'Password reset link sent successfully!']);
    }

    /**
     * Check reset password link.
     * @param Request $request
     * @return JsonResponse
     */
    public function checkPasswordLink(Request $request): JsonResponse
    {
        if (!$request->hasValidSignature() || !$request->user) {
            return response()->json(['message' => __('errors.auth.invalid_signature')], 401);
        }

        return response()->json([
            'message' => 'Password Link is Valid!',
            'data' => User::query()->find($request->user)?->only(['id', 'name', 'email']),
            // One-time broker token the SPA must send back to reset-password.
            'token' => $request->query('token'),
        ]);
    }

    /**
     * Sets a new password with the one-time token of the Laravel password broker (the `token` the reset email carries).
     * The token is single use and expires (config auth.passwords.users.expire); a wrong email or token answers the same
     * 422 so the response does not reveal whether the account exists. Every API token of the account is revoked.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        // Failures are counted per e-mail AND address, so a stranger cannot lock the real owner out (audit W-04).
        $limitKey = 'app-reset:' . Str::lower((string) $request->input('email')) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return response()->json(['message' => __('errors.too_many_attempts'), 'code' => 'too_many_attempts'], 429);
        }

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
                // Browser sessions (cookie auth) die with the password too.
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
                }
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            RateLimiter::hit($limitKey, 15 * 60);

            return response()->json(['message' => __('errors.auth.invalid_reset_token'), 'code' => 'invalid_reset_token'], 422);
        }

        return response()->json(['message' => 'Password reset successfully!']);
    }

    /**
     * Authenticates the user for the mobile application login.
     *
     * @param AppLoginRequest $request The request containing user login information.
     *
     * @return JsonResponse Returns a JSON response with the authentication token, user data, and a success message if login is successful.
     * If authentication fails due to incorrect credentials, returns a JSON response with an error message and status code 401 (Unauthorized).
     * If the user does not have the required role for the app login, returns a JSON response with an error message and status code 403 (Forbidden).
     * @throws ConnectionException
     */
    public function appLogin(AppLoginRequest $request): JsonResponse
    {
        if (config('services.hcaptcha.enabled')) {
            $token = $request->input('hcaptcha_token');

            if (!$token || !$this->verifyHCaptcha($token) ) {
                return response()->json(['message' => __('errors.auth.captcha_invalid')], 422);
            }
        }

        $user = User::query()
            ->with(['lastLoginSession', 'activeEvent'])
            ->where('email', $request->input('email'))
            ->whereNotNull('email_verified_at')
            ->first();

        // Per account and address, on top of the per-IP limit of the API: a botnet cannot guess one password.
        $limitKey = 'app-login:' . Str::lower((string) $request->input('email')) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return response()->json(['message' => __('errors.too_many_attempts'), 'code' => 'too_many_attempts'], 429)
                ->header('Retry-After', RateLimiter::availableIn($limitKey));
        }

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($limitKey, 15 * 60);

            return response()->json(['message' => __('errors.auth.invalid_credentials')], 401);
        }

        RateLimiter::clear($limitKey);

        if ($user->suspended_at) {
            return EnsureNotSuspended::response();
        }

        $remember = $request->input('remember', false);
        Auth::login($user, $remember);

        // A new session id after signing in: a session id planted before the login must not survive it.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        
        if ($user->is_first_login) {
            $user->is_first_login = false;
            $user->save();
        }
        
        UserLoggedInEvent::dispatch($user, $request);

        return response()->json([
            'message' => 'Logged in successfully!',
            'user' => $user,
        ]);
    }
    
    /**
     * Check if authentication is ready.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkAuthReady(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Authentication is ready',
            'status' => 'success',
            'impersonating' => $request->attributes->get('impersonation_session')?->banner(),
        ]);
    }

    /**
     * Verify the hCaptcha token.
     *
     * @param string $token The hCaptcha token to verify.
     *
     * @return bool Returns true if the verification is successful, false otherwise.
     * @throws ConnectionException
     */
    private function verifyHCaptcha(string $token): bool
    {
        $response = Http::asForm()->post(
            'https://hcaptcha.com/siteverify',
            [
                'secret' => config('services.hcaptcha.secret'),
                'response' => $token,
                'remoteip' => request()->ip(),
            ]
        );

        return $response->ok() && $response->json('success') === true;
    }

    /**
     * Logout the authenticated user by deleting their current access token.
     *
     * @param Request $request The incoming request containing the user information.
     *
     * @return JsonResponse A JSON response indicating the success of the logout operation.
     */
    public function appLogout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            // A cookie session (the SPA login) has no token to delete: close the session itself.
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        UserLoggedOutEvent::dispatch($user);

        return response()->json(['message' => 'Logged out successfully!']);
    }

}
