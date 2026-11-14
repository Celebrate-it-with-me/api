<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Acts as the user through a real panel token that has just re-authenticated, for the routes behind `admin.reauth`
     * (a plain `actingAs` is a cookie session, which can never pass it).
     */
    public function actingAsReauthed(User $user): static
    {
        $new = $user->createToken('test', ['*']);
        $new->accessToken->forceFill(['client' => 'admin', 'reauthenticated_at' => now()])->save();
        $user->withAccessToken($new->accessToken);

        return $this->actingAs($user, 'sanctum');
    }
}
