<?php

namespace App\Http\Controllers;

use App\Http\Services\Auth\SocialAuthService;
use Illuminate\Http\RedirectResponse;

class SocialAuthController extends Controller
{
    public function __construct(
        protected SocialAuthService $socialAuthService
    ) {}

    public function redirect(string $provider): RedirectResponse
    {
        return $this->socialAuthService->redirect($provider);
    }

    public function callback(string $provider): RedirectResponse
    {
        return $this->socialAuthService->callback($provider);
    }
}
