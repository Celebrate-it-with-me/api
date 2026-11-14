<?php

namespace Tests;

use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * The panel only accepts real tokens issued by its own login (client `admin`): a staff member acts through one by
     * default, so the many admin tests keep their plain `actingAs($staff)`. Use actingAsCookie() for a cookie session.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        if ($guard === null && $user instanceof User && $user->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            $new = $user->createToken('test', ['*']);
            $new->accessToken->forceFill(['client' => 'admin'])->save();
            $user->withAccessToken($new->accessToken);

            return parent::actingAs($user, 'sanctum');
        }

        return parent::actingAs($user, $guard);
    }

    /** The plain text of a real panel token (client `admin`), to send as a Bearer. */
    public function adminBearer(User $user, bool $reauthenticated = false): string
    {
        $new = $user->createToken('test', ['*']);
        $new->accessToken->forceFill(['client' => 'admin', 'reauthenticated_at' => $reauthenticated ? now() : null])->save();

        return $new->plainTextToken;
    }

    /** A plain cookie (web guard) session, as the organizers' app login opens it. */
    public function actingAsCookie(Authenticatable $user): static
    {
        return parent::actingAs($user);
    }

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
