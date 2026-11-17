<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    /** The link in the mail (public, signed): verifies and sends the person to the app. */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        return redirect()->away($this->verification->redirectUrl($this->verification->verify($request, $id, $hash)));
    }

    /** "Reenviar correo": 3 per 10 minutes per user and per IP (throttle `email-resend`). */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['verified' => true]);
        }

        $this->verification->send($user);

        return response()->json(['verified' => false]);
    }
}
