<?php

namespace App\Http\Controllers;

use App\Http\Services\Auth\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function __construct(
        protected SocialAuthService $socialAuthService
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        return $this->socialAuthService->redirect($request, $provider);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        return $this->socialAuthService->callback($request, $provider);
    }
}
