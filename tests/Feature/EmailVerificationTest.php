<?php

use App\Events\UserRegistered;
use App\Http\Services\Auth\SocialAuthService;
use App\Mail\ConfirmAccountEmail;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function unverifiedUser(): User
{
    return User::factory()->unverified()->create();
}

function verificationOwnedEvent(User $owner): Events
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

function verificationUrl(User $user, ?int $minutes = 60, ?string $hash = null): string
{
    return URL::temporarySignedRoute('auth.verify.email', now()->addMinutes($minutes), [
        'id' => $user->id, 'hash' => $hash ?? sha1($user->email),
    ]);
}

describe('registration', function () {
    it('sends the verification mail, with a signed link that lasts 60 minutes, and lets her in', function () {
        Mail::fake();

        $this->postJson('/api/v1/app/register', [
            'name' => 'Ana Ruiz', 'email' => 'ana@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('user.emailVerified', false);

        Mail::assertSent(ConfirmAccountEmail::class, function (ConfirmAccountEmail $mail) {
            parse_str((string) parse_url($mail->confirmUrl, PHP_URL_QUERY), $q);

            return $mail->hasTo('ana@example.com')
                && str_contains($mail->confirmUrl, '/api/v1/app/email/verify/')
                && abs((int) $q['expires'] - now()->addMinutes(60)->timestamp) < 10;
        });

        $this->postJson('/api/v1/app/login', ['email' => 'ana@example.com', 'password' => 'password123', 'device' => 'x'])
            ->assertOk()->assertJsonPath('user.emailVerified', false);
    });

    it('also sends it on the UserRegistered event', function () {
        Mail::fake();
        event(new UserRegistered(unverifiedUser()));
        Mail::assertSent(ConfirmAccountEmail::class);
    });
});

describe('verify link', function () {
    it('verifies and redirects to the app with status ok', function () {
        $user = unverifiedUser();

        $this->get(verificationUrl($user))
            ->assertRedirect(rtrim((string) config('app.frontend_app.url'), '/') . '/email-verified?status=ok');

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    });

    it('is idempotent', function () {
        $user = unverifiedUser();
        $this->get(verificationUrl($user));
        $first = $user->fresh()->email_verified_at;

        $this->get(verificationUrl($user))->assertRedirectContains('status=ok');
        expect($user->fresh()->email_verified_at->equalTo($first))->toBeTrue();
    });

    it('redirects expired when the right link is past its time', function () {
        $user = unverifiedUser();
        $url = verificationUrl($user);
        $this->travel(61)->minutes();

        $this->get($url)->assertRedirectContains('/email-verified?status=expired');
        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    it('redirects invalid when the link was altered or the hash is not hers', function () {
        $user = unverifiedUser();
        $other = unverifiedUser();

        $this->get(str_replace('/' . $user->id . '/', '/' . $other->id . '/', verificationUrl($user)))
            ->assertRedirectContains('status=invalid');
        $this->get(verificationUrl($user, 60, sha1('someone@else.com')))->assertRedirectContains('status=invalid');
        $this->get(verificationUrl($user) . 'x')->assertRedirectContains('status=invalid');
        $this->get('/api/v1/app/email/verify/' . $user->id . '/' . sha1($user->email))->assertRedirectContains('status=invalid');

        expect($user->fresh()->email_verified_at)->toBeNull()->and($other->fresh()->email_verified_at)->toBeNull();
    });
});

describe('resend', function () {
    it('requires authentication', function () {
        $this->postJson('/api/v1/app/email/resend')->assertUnauthorized();
    });

    it('sends the mail to an unverified user', function () {
        Mail::fake();
        Sanctum::actingAs(unverifiedUser());

        $this->postJson('/api/v1/app/email/resend')->assertOk()->assertExactJson(['verified' => false]);
        Mail::assertSent(ConfirmAccountEmail::class, 1);
    });

    it('answers verified without sending when already verified', function () {
        Mail::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/app/email/resend')->assertOk()->assertExactJson(['verified' => true]);
        Mail::assertNothingSent();
    });

    it('allows 3 per 10 minutes per user, then 429', function () {
        Mail::fake();
        Sanctum::actingAs(unverifiedUser());

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/app/email/resend')->assertOk();
        }
        $this->postJson('/api/v1/app/email/resend')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        Mail::assertSent(ConfirmAccountEmail::class, 3);

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/app/email/resend')->assertOk();
    });

    it('limits per IP across users', function () {
        Mail::fake();
        foreach (range(1, 3) as $i) {
            Sanctum::actingAs(unverifiedUser());
            $this->postJson('/api/v1/app/email/resend')->assertOk();
        }
        Sanctum::actingAs(unverifiedUser());
        $this->postJson('/api/v1/app/email/resend')->assertStatus(429);
    });
});

describe('payload', function () {
    it('carries emailVerified on login and GET user', function () {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'password123', 'device' => 'x'])
            ->assertOk()->assertJsonPath('user.emailVerified', true);

        $unverified = unverifiedUser();
        Sanctum::actingAs($unverified);
        $this->getJson('/api/v1/app/user')->assertJsonPath('data.user.emailVerified', false);
    });
});

describe('guard', function () {
    it('answers 403 email_not_verified when publishing or buying unverified', function () {
        $user = unverifiedUser();
        $event = verificationOwnedEvent($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/app/event/{$event->id}/publish")
            ->assertForbidden()->assertJsonPath('code', 'email_not_verified')->assertJsonStructure(['message']);
        $this->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")
            ->assertForbidden()->assertJsonPath('code', 'email_not_verified');
    });

    it('does not block anything else, nor a verified user', function () {
        $user = unverifiedUser();
        $event = verificationOwnedEvent($user);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/app/event/{$event->id}/purchase/quote")->assertOk();
        $this->getJson('/api/v1/app/user')->assertOk();

        $user->forceFill(['email_verified_at' => now()])->save();
        $res = $this->postJson("/api/v1/app/event/{$event->id}/publish");
        expect($res->json('code'))->not->toBe('email_not_verified');
        $res = $this->postJson("/api/v1/app/event/{$event->id}/purchase/checkout");
        expect($res->json('code'))->not->toBe('email_not_verified');
    });
});

describe('social and backfill', function () {
    it('marks a user who comes in through a social provider as verified', function () {
        $socialUser = (new Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => 'g-1', 'name' => 'Sofia', 'email' => 'sofia@example.com']);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $existing = User::factory()->unverified()->create(['email' => 'sofia@example.com']);
        app(SocialAuthService::class)->callback(Request::create('/oauth/google/callback'), 'google');

        expect($existing->fresh()->email_verified_at)->not->toBeNull()
            ->and(SocialAccount::query()->where('user_id', $existing->id)->exists())->toBeTrue();
    });

    it('backfills existing users and is idempotent', function () {
        $a = unverifiedUser();
        $b = User::factory()->create(['email_verified_at' => now()->subYear()->startOfSecond()]);
        $migration = require database_path('migrations/2027_07_01_000001_backfill_users_email_verified_at.php');

        $migration->up();
        $verifiedAt = $a->fresh()->email_verified_at;
        expect($verifiedAt)->not->toBeNull()
            ->and($b->fresh()->email_verified_at->equalTo($b->email_verified_at))->toBeTrue();

        $this->travel(5)->minutes();
        $migration->up();
        expect($a->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue()
            ->and(DB::table('users')->whereNull('email_verified_at')->count())->toBe(0);
    });
});
