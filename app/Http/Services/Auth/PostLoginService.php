<?php

namespace App\Http\Services\Auth;

use App\Events\UserLoggedInEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Everything that happens after the credentials (password or social provider) were accepted. */
class PostLoginService
{
    public function complete(User $user, Request $request, bool $remember = false): void
    {
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
    }
}
