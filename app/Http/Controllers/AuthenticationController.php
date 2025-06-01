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
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function adminLogin(LoginRequest $request): string
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user->createToken($request->input('device'))->plainTextToken;
    }

    /**
     * Register a new user based on the provided request data.
     *
     * @param  AppRegisterRequest  $request  The request object containing user data to be validated and registered.
     * @return JsonResponse A JSON response containing a message and the registered user data with HTTP status code 201.
     *
     * @throws ConnectionException
     */
    public function appRegister(AppRegisterRequest $request): JsonResponse
    {
        if (config('services.hcaptcha.enabled')) {
            $token = $request->input('hcaptcha_token');

            if (! $token || ! $this->verifyHCaptcha($token)) {
                return response()->json(['message' => 'Invalid hCaptcha token.'], 422);
            }
        }

        $user = User::query()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new UserRegistered($user));

        return response()->json([
            'message' => 'User registered successfully!',
            'user' => $user,
        ], 201);
    }

    /**
     * Confirm the user's email address.
     */
    public function confirmEmail(Request $request, User $user): JsonResponse
    {
        if (! $request->hasValidSignature() || ! $request->user) {
            return response()->json(['message' => 'Invalid or expired signature.'], 401);
        }

        $user = User::query()->find($request->user);

        if (! $user || ! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();
        }

        return response()->json(['message' => 'Email verified successfully!']);
    }

    /**
     * Forgot Password functionality.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user) {
            return response()->json(['message' => 'Password reset link sent successfully!']);
        }

        ResetPasswordEvent::dispatch($user);

        return response()->json(['message' => 'Password reset link sent successfully!']);
    }

    /**
     * Check reset password link.
     */
    public function checkPasswordLink(Request $request): JsonResponse
    {
        if (! $request->hasValidSignature() || ! $request->user) {
            return response()->json(['message' => 'Invalid or expired signature.'], 401);
        }

        return response()->json([
            'message' => 'Password Link is Valid!',
            'data' => User::find($request->user),
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->input('email'))
            ->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json(['message' => 'Password reset successfully!']);
    }

    /**
     * Authenticates the user for the mobile application login.
     *
     * @param  AppLoginRequest  $request  The request containing user login information.
     * @return JsonResponse Returns a JSON response with the authentication token, user data, and a success message if login is successful.
     *                      If authentication fails due to incorrect credentials, returns a JSON response with an error message and status code 401 (Unauthorized).
     *                      If the user does not have the required role for the app login, returns a JSON response with an error message and status code 403 (Forbidden).
     *
     * @throws ConnectionException
     */
    public function appLogin(AppLoginRequest $request): JsonResponse
    {
        if (config('services.hcaptcha.enabled')) {
            $token = $request->input('hcaptcha_token');

            if (! $token || ! $this->verifyHCaptcha($token)) {
                return response()->json(['message' => 'Invalid hCaptcha token.'], 422);
            }
        }

        $user = User::query()
            ->with(['lastLoginSession', 'activeEvent'])
            ->where('email', $request->input('email'))
            ->whereNotNull('email_verified_at')
            ->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid Credentials'], 401);
        }

        $remember = $request->input('remember', false);
        $expiration = $remember ? now()->addDays(30) : now()->addhours(5);

        $token = $user->createToken(
            $request->input('device'),
            ['*'],
            $expiration
        )->plainTextToken;

        UserLoggedInEvent::dispatch($user, $request);

        return response()->json([
            'message' => 'Logged in successfully!',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Verify the hCaptcha token.
     *
     * @param  string  $token  The hCaptcha token to verify.
     * @return bool Returns true if the verification is successful, false otherwise.
     *
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
     * @param  Request  $request  The incoming request containing the user information.
     * @return JsonResponse A JSON response indicating the success of the logout operation.
     */
    public function appLogout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        UserLoggedOutEvent::dispatch($request->user());

        return response()->json(['message' => 'Logged out successfully!']);
    }
}
