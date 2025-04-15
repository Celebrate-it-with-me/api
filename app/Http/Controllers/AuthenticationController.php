<?php

namespace App\Http\Controllers;

use App\Events\UserLoggedInEvent;
use App\Events\UserLoggedOutEvent;
use App\Events\UserRegistered;
use App\Http\Requests\Auth\AppLoginRequest;
use App\Http\Requests\Auth\AppRegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function adminLogin(LoginRequest $request): string
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user->createToken($request->input('device'))->plainTextToken;
    }

    /**
     * Register a new user based on the provided request data.
     *
     * @param AppRegisterRequest $request The request object containing user data to be validated and registered.
     *
     * @return JsonResponse A JSON response containing a message and the registered user data with HTTP status code 201.
     */
    public function appRegister(AppRegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('appUser');
        
        event(new UserRegistered($user));

        return response()->json([
           'message' => 'User registered successfully!',
           'user' => $user,
        ], 201);
    }

    /**
     * Authenticates the user for the mobile application login.
     *
     * @param AppLoginRequest $request The request containing user login information.
     *
     * @return JsonResponse Returns a JSON response with the authentication token, user data, and a success message if login is successful.
     * If authentication fails due to incorrect credentials, returns a JSON response with an error message and status code 401 (Unauthorized).
     * If the user does not have the required role for the app login, returns a JSON response with an error message and status code 403 (Forbidden).
     */
    public function appLogin(AppLoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->with('lastLoginSession')
            ->where('email', $request->input('email'))
            ->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid Credentials'], 401);
        }

        if (!$user->hasRole('appUser')) {
            return response()->json(['message' => 'Access Denied. You are not authorized to access this page.'], 403);
        }

        $token = $user->createToken($request->input('device'))->plainTextToken;

        UserLoggedInEvent::dispatch($user, $request);
        
        return response()->json([
            'message' => 'Logged in successfully!',
            'user' => $user,
            'token' => $token
        ]);
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
        $request->user()->currentAccessToken()->delete();

        UserLoggedOutEvent::dispatch($request->user());
        
        return response()->json(['message' => 'Logged out successfully!']);
    }

}
