<?php

use App\Http\Services\Payments\PurchaseService;
use App\Http\Services\WhatsApp\WhatsAppBroadcaster;
use App\Jobs\SendWhatsAppMessage;
use App\Mail\AccountDeletedMail;
use App\Models\AdminAuditLog;
use App\Models\EventCollaborationInvite;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\WhatsApp\Message;
use App\Support\AdminPermissions;
use App\Support\Mail\MailRegistry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const ACC_PASSWORD = 'OriginalPass1';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Mail::fake();
    RateLimiter::clear('x');
});

function accUser(array $attrs = []): User
{
    return User::factory()->create($attrs + ['password' => ACC_PASSWORD, 'name' => 'Marisol Reyes Soto', 'email' => 'marisol.original@example.com', 'phone' => '+13055550101']);
}

function accEvent(User $owner, string $status = 'draft', array $attrs = []): Events
{
    $event = Events::factory()->create($attrs + [
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'status' => $status, 'published_at' => $status === 'published' ? now() : null,
        'event_name' => "Los XV de {$owner->id}", 'custom_url_slug' => 'xv-' . uniqid(),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Rosa', 'email' => 'rosa@example.com', 'code' => 'C' . random_int(10000, 99999)]);

    return $event;
}

function accMember(Events $event, User $user, string $role): void
{
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', $role)->value('id')]);
}

function accPurchase(Events $event, User $buyer, string $status = 'pending', array $attrs = []): EventPurchase
{
    return EventPurchase::create($attrs + [
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => $status,
    ]);
}

function accDelete($test, User $user, array $body = [])
{
    app('auth')->forgetGuards();

    return $test->actingAsCookie($user)->deleteJson('/api/v1/app/account', $body + ['email' => $user->email, 'current_password' => ACC_PASSWORD]);
}

/** Every text value of the tables that could hold the original address or name of the person. */
function accTraces(string $needle): array
{
    $found = [];
    foreach (['users', 'event_collaboration_invites', 'password_reset_tokens', 'social_accounts', 'billing_profiles', 'user_preferences', 'support_requests', 'sessions', 'notifications', 'admin_audit_log', 'guests'] as $table) {
        $hits = DB::table($table)->get()->filter(fn ($row) => str_contains(mb_strtolower(json_encode($row, JSON_UNESCAPED_UNICODE)), mb_strtolower($needle)));
        if ($hits->isNotEmpty()) {
            $found[] = $table;
        }
    }

    return $found;
}

describe('deletion-preview', function () {
    it('requires login', function () {
        $this->getJson('/api/v1/app/account/deletion-preview')->assertUnauthorized();
        $this->deleteJson('/api/v1/app/account')->assertUnauthorized();
    });

    it('counts published, unpublished and collaborating events, and tells whether she has a password', function () {
        $me = accUser();
        accEvent($me, 'published');
        accEvent($me, 'published');
        accEvent($me, 'draft');
        accMember(accEvent(accUser(['email' => 'otra@example.com']), 'published'), $me, 'editor');
        accPurchase(accEvent(accUser(['email' => 'o2@example.com']), 'draft'), $me, 'pending');

        $this->actingAs($me)->getJson('/api/v1/app/account/deletion-preview')->assertOk()
            ->assertExactJson(['data' => [
                'email' => $me->email, 'hasPassword' => true,
                'events' => ['published' => 2, 'unpublished' => 1, 'collaborating' => 1],
                'archiveDays' => 30, 'pendingPurchases' => 1,
            ]]);
    });

    it('says hasPassword false for accounts born from a social login', function () {
        $me = accUser();
        $me->forceFill(['has_password' => false])->save();

        $this->actingAs($me)->getJson('/api/v1/app/account/deletion-preview')->assertOk()->assertJsonPath('data.hasPassword', false);
    });

    it('marks accounts created through the social login flow as without password, and a password reset gives her one', function () {
        $user = User::factory()->create(['email' => 'social@example.com']);
        $user->forceFill(['has_password' => false])->save();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/app/reset-password', ['email' => 'social@example.com', 'token' => $token, 'password' => 'BrandNewPass12', 'password_confirmation' => 'BrandNewPass12'])->assertOk();

        expect($user->refresh()->has_password)->toBeTrue();
    });
});

describe('DELETE account', function () {
    it('archives published events, deletes unpublished ones, leaves collaborations and anonymizes the account', function () {
        $me = accUser();
        UserPreference::create(['user_id' => $me->id, 'language' => 'en', 'timezone' => 'America/New_York']);
        $published = accEvent($me, 'published');
        $draft = accEvent($me, 'draft');
        $draftGuestIds = Guest::where('event_id', $draft->id)->pluck('id');
        $others = accEvent(accUser(['email' => 'duena@example.com', 'name' => 'Dueña Ajena']), 'published');
        accMember($others, $me, 'editor');
        // Invitations: one addressed to her (any case), one she sent, one for somebody else.
        EventCollaborationInvite::create(['event_id' => $others->id, 'email' => 'Marisol.Original@Example.com', 'role' => 'viewer', 'token' => 'tok-1', 'status' => 'pending', 'invited_by_user_id' => $others->owner->user_id]);
        EventCollaborationInvite::create(['event_id' => $others->id, 'email' => 'tercera@example.com', 'role' => 'viewer', 'token' => 'tok-2', 'status' => 'pending', 'invited_by_user_id' => $others->owner->user_id]);
        SocialAccount::create(['user_id' => $me->id, 'provider' => 'google', 'provider_user_id' => 'g-1', 'provider_email' => 'marisol.original@example.com']);
        DB::table('password_reset_tokens')->insert(['email' => $me->email, 'token' => 'x', 'created_at' => now()]);
        $me->createToken('phone');
        $me->createToken('laptop');
        $me->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'avatar' => 'avatars/me.png', 'last_active_event_id' => $published->id])->save();
        $original = $me->email;

        $res = accDelete($this, $me)->assertOk();

        expect($res->json('data'))->toMatchArray(['archivedEvents' => 1, 'deletedEvents' => 1])->and($res->json('data.deletedAt'))->toBeString();

        // The person.
        $me->refresh();
        expect($me->name)->toBe('Cuenta eliminada')
            ->and($me->email)->toBe("deleted-{$me->id}@invalid.quinvite")
            ->and($me->phone)->toBeNull()->and($me->avatar)->toBeNull()->and($me->two_factor_secret)->toBeNull()
            ->and($me->two_factor_recovery_codes)->toBeNull()->and($me->last_active_event_id)->toBeNull()
            ->and($me->anonymized_at)->not->toBeNull()->and($me->account_deleted_at)->not->toBeNull()
            ->and($me->tokens()->count())->toBe(0)
            ->and(Hash::check(ACC_PASSWORD, $me->password))->toBeFalse()
            ->and(EventUserRole::where('user_id', $me->id)->count())->toBe(0);
        expect(accTraces($original))->toBe([])->and(accTraces('Marisol Reyes'))->toBe([]);
        // Other people's invitations are untouched; hers is gone.
        expect(EventCollaborationInvite::where('email', 'tercera@example.com')->exists())->toBeTrue()
            ->and(EventCollaborationInvite::whereRaw('LOWER(email) = ?', [strtolower($original)])->exists())->toBeFalse();

        // The events.
        $published->refresh();
        expect($published->status)->toBe('archived')->and($published->archived_at)->not->toBeNull()
            ->and($published->purge_after->isSameDay(now()->addDays(30)))->toBeTrue()
            ->and(Guest::where('event_id', $published->id)->count())->toBe(1);
        expect(Events::withTrashed()->find($draft->id))->toBeNull()->and(Guest::whereIn('id', $draftGuestIds)->count())->toBe(0);
        expect($others->refresh()->status)->toBe('published')->and($others->purge_after)->toBeNull();

        // The goodbye mail went to the address she knew, in her language.
        Mail::assertSent(AccountDeletedMail::class, fn (AccountDeletedMail $m) => $m->hasTo($original) && $m->locale === 'en' && $m->archivedEvents === 1);

        // The old address no longer logs in anywhere.
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/app/login', ['email' => $original, 'password' => ACC_PASSWORD, 'device' => 'x'])->assertStatus(401);
    });

    it('closes the session: the token that made the call stops working', function () {
        $me = accUser();
        $plain = $me->createToken('web')->plainTextToken;

        app('auth')->forgetGuards();
        $this->deleteJson('/api/v1/app/account', ['email' => $me->email, 'current_password' => ACC_PASSWORD], ['Authorization' => "Bearer {$plain}"])->assertOk();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/app/user', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
    });

    it('writes an audit row with ids and counts but no email and no name', function () {
        $me = accUser();
        accEvent($me, 'published');
        accEvent($me, 'draft');

        accDelete($this, $me)->assertOk();

        $row = AdminAuditLog::where('action', 'account.delete')->sole();
        expect($row->user_id)->toBe($me->id)->and($row->actor_id)->toBe($me->id)
            ->and($row->after)->toBe(['archived_events' => 1, 'deleted_events' => 1, 'collaborations_left' => 0])
            ->and($row->impersonated_by)->toBeNull();
        $dump = strtolower(json_encode($row->toArray()));
        expect($dump)->not->toContain('marisol')->not->toContain('example.com');
    });

    it('is accepted with the email in any case and with surrounding spaces', function () {
        $me = accUser();

        accDelete($this, $me, ['email' => '  ' . strtoupper($me->email) . ' '])->assertOk();
    });

    it('does not delete anything with the wrong email or password: 422 with the contract codes', function () {
        $me = accUser();
        $event = accEvent($me, 'draft');

        accDelete($this, $me, ['email' => 'otra@example.com'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'email_mismatch');
        accDelete($this, $me, ['current_password' => 'nope'])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'invalid_current_password');
        accDelete($this, $me, ['current_password' => ''])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'invalid_current_password');
        app('auth')->forgetGuards();
        $this->actingAs($me)->deleteJson('/api/v1/app/account', [])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'email_mismatch')->assertJsonPath('errors.current_password.0', 'invalid_current_password');

        expect($me->refresh()->email)->toBe('marisol.original@example.com')->and(Events::find($event->id))->not->toBeNull();
        Mail::assertNothingSent();
    });

    it('needs no password for a social-login account, but still the email', function () {
        $me = accUser();
        $me->forceFill(['has_password' => false])->save();
        accEvent($me, 'draft');

        app('auth')->forgetGuards();
        $this->actingAs($me)->deleteJson('/api/v1/app/account', ['email' => 'otra@example.com'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'email_mismatch')->assertJsonMissingPath('errors.current_password');

        app('auth')->forgetGuards();
        $this->actingAs($me)->deleteJson('/api/v1/app/account', ['email' => $me->email])->assertOk()->assertJsonPath('data.deletedEvents', 1);
        expect($me->refresh()->anonymized_at)->not->toBeNull();
    });

    it('refuses a staff account with 403 staff_account and changes nothing', function () {
        $staff = accUser(['email' => 'staff@example.com']);
        $staff->assignRole(AdminPermissions::SUPPORT);

        accDelete($this, $staff)->assertForbidden()->assertJsonPath('code', 'staff_account');

        expect($staff->refresh()->email)->toBe('staff@example.com')->and($staff->anonymized_at)->toBeNull();
    });

    it('answers 409 pending_payment while a recent checkout is open, and ignores an abandoned one', function () {
        $me = accUser();
        $event = accEvent($me, 'draft');
        $stale = accPurchase($event, $me, 'pending');
        DB::table('event_purchases')->where('id', $stale->id)->update(['created_at' => now()->subDays(3)]);
        accPurchase($event, $me, 'paid');
        $open = accPurchase($event, $me, 'pending');

        accDelete($this, $me)->assertStatus(409)->assertJsonPath('code', 'pending_payment');
        expect($me->refresh()->anonymized_at)->toBeNull();

        $open->update(['status' => 'failed']);
        accDelete($this, $me)->assertOk();
    });

    it('limits attempts to 5 per hour per account, wrong ones included, answering 429 too_many_attempts', function () {
        $me = accUser();
        $other = accUser(['email' => 'otra.cuenta@example.com']);

        foreach (range(1, 5) as $i) {
            accDelete($this, $me, ['current_password' => 'nope'])->assertUnprocessable();
        }
        accDelete($this, $me)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        expect($me->refresh()->anonymized_at)->toBeNull();

        // Another account is not locked out.
        accDelete($this, $other)->assertOk();
    });

    it('keeps purchases, invoices and credit notes, with the event row scrubbed once purged', function () {
        $me = accUser();
        $event = accEvent($me, 'published', ['event_name' => 'Los XV de Valentina']);
        $purchase = accPurchase($event, $me);
        app(PurchaseService::class)->markPaid($purchase);
        $invoice = Invoice::where('purchase_id', $purchase->id)->sole();
        $draft = accEvent($me, 'draft');
        $draftPurchase = accPurchase($draft, $me, 'paid', ['paid_at' => now()]);

        accDelete($this, $me)->assertOk();

        expect(EventPurchase::whereKey([$purchase->id, $draftPurchase->id])->count())->toBe(2)
            ->and($invoice->refresh()->user_id)->toBe($me->id)->and($invoice->lines[0]['event_name'])->toBe('Los XV de Valentina');
        // The unpublished event with a purchase cannot disappear (restrict): only its row stays, emptied.
        $row = Events::withTrashed()->find($draft->id);
        expect($row)->not->toBeNull()->and($row->trashed())->toBeTrue()->and($row->event_name)->toBe('Evento eliminado')
            ->and(Guest::where('event_id', $draft->id)->count())->toBe(0);

        // After the grace period the published one goes the same way.
        $this->travel(31)->days();
        Artisan::call('account:purge-archived');
        $kept = Events::withTrashed()->find($event->id);
        expect($kept->trashed())->toBeTrue()->and($kept->event_name)->toBe('Evento eliminado')->and($kept->custom_url_slug)->toBe("deleted-{$event->id}")
            ->and(Invoice::whereKey($invoice->id)->exists())->toBeTrue()->and(Guest::where('event_id', $event->id)->count())->toBe(0);
    });

    it('works on the full demo data (every table that hangs from an event or a user)', function () {
        $this->seed(DemoSeeder::class);
        $valentina = Events::where('custom_url_slug', 'xv-de-valentina')->firstOrFail();
        $owner = $valentina->owner->user;
        $owner->forceFill(['password' => ACC_PASSWORD])->save();
        $original = $owner->email;

        accDelete($this, $owner)->assertOk();

        expect(accTraces($original))->toBe([]);
        $this->travel(31)->days();
        expect(Artisan::call('account:purge-archived'))->toBe(0);
        expect(Events::withTrashed()->where('id', $valentina->id)->exists())->toBeFalse();
    });
});

describe('events archived by an account deletion', function () {
    it('keeps opening the public invitation, read only: RSVP closed', function () {
        $this->seed(InvitationTemplateSeeder::class);
        $me = accUser();
        $event = accEvent($me, 'published', ['event_name' => 'Los XV de Valentina']);
        $guest = Guest::where('event_id', $event->id)->first();
        $guest->update(['rsvp_status' => 'pending']);
        $url = "/api/v1/app/invitation/{$event->custom_url_slug}/{$guest->code}";
        $this->getJson($url)->assertOk();

        accDelete($this, $me)->assertOk();
        app('auth')->forgetGuards();

        $res = $this->getJson($url)->assertOk()->assertJsonPath('data.event.name', 'Los XV de Valentina');
        $rsvp = collect($res->json('data.sections'))->firstWhere('type', 'rsvp');
        if ($rsvp) {
            expect($rsvp['data']['isOpen'])->toBeFalse();
        }
        $this->postJson("{$url}/rsvp", ['guest' => ['rsvpStatus' => 'attending']])->assertStatus(422);
        expect($guest->refresh()->rsvp_status)->not->toBe('attending');
        $this->get("{$url}/calendar.ics")->assertOk();
    });

    it('does not serve the invitation of an event archived by hand', function () {
        $this->seed(InvitationTemplateSeeder::class);
        $me = accUser();
        $event = accEvent($me, 'archived', ['published_at' => now()]);
        $guest = Guest::where('event_id', $event->id)->first();

        $this->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/{$guest->code}")->assertNotFound();
    });

    it('accepts no more WhatsApp sends, and queued messages fail instead of leaving', function () {
        [$event, $users] = waEvent();
        $guest = waGuest($event);
        $message = Message::create([
            'event_id' => $event->id, 'guest_id' => $guest->id, 'batch_id' => (string) Str::uuid(), 'template_key' => 'invitation', 'locale' => 'es', 'type' => 'template',
            'to_phone' => '+13055550199', 'params' => [], 'status' => 'queued', 'queued_at' => now(),
        ]);
        $users['owner']->forceFill(['password' => ACC_PASSWORD])->save();

        accDelete($this, $users['owner'])->assertOk();
        $event->refresh();

        try {
            app(WhatsAppBroadcaster::class)->send($event, 'invitation', 'all');
            $this->fail('A send on an archived event must be refused.');
        } catch (HttpResponseException $e) {
            expect($e->getResponse()->getStatusCode())->toBe(409)->and(json_decode($e->getResponse()->getContent(), true)['code'])->toBe('event_archived');
        }

        SendWhatsAppMessage::dispatchSync($message->id);
        expect($message->refresh()->status)->toBe('failed')->and($message->error_code)->toBe('event_archived');
    });

    it('cannot be restored by a collaborator, while an event archived by hand can', function () {
        $owner = accUser();
        $editor = accUser(['email' => 'editora@example.com']);
        $event = accEvent($owner, 'published');
        accMember($event, $editor, 'editor');
        $manual = accEvent(accUser(['email' => 'm@example.com']), 'draft');
        accMember($manual, $editor, 'editor');
        $this->actingAs($editor)->postJson("/api/v1/app/event/{$manual->id}/archive")->assertOk();
        $this->actingAs($editor)->postJson("/api/v1/app/event/{$manual->id}/restore")->assertOk();

        accDelete($this, $owner)->assertOk();
        app('auth')->forgetGuards();

        $this->actingAs($editor)->postJson("/api/v1/app/event/{$event->id}/restore")->assertUnprocessable();
        expect($event->refresh()->status)->toBe('archived');
    });
});

describe('account:purge-archived', function () {
    it('removes the events whose date passed, with guests and files, and leaves the rest', function () {
        Storage::fake('public');
        Storage::fake('sweet-memories');
        $me = accUser();
        $due = accEvent($me, 'published');
        $later = accEvent($me, 'published');
        $other = accEvent(accUser(['email' => 'otra@example.com']), 'published');
        foreach ([$due, $later] as $e) {
            Storage::disk('sweet-memories')->put("images/event/{$e->id}/memories-images/a.jpg", 'x');
            Storage::disk('sweet-memories')->put("images/event/{$e->id}/memories-images/thumbs/a.jpg", 'x');
            DB::table('sweet_memories_images')->insert(['event_id' => $e->id, 'image_path' => "images/event/{$e->id}/memories-images/a.jpg", 'image_name' => 'a.jpg', 'image_original_name' => 'a.jpg', 'image_size' => 1, 'thumbnail_path' => "images/event/{$e->id}/memories-images/thumbs/a.jpg", 'thumbnail_name' => 'a.jpg', 'created_at' => now(), 'updated_at' => now()]);
            Storage::disk('public')->put("sound/background-music/{$e->id}/song.mp3", 'x');
            DB::table('background_music')->insert(['event_id' => $e->id, 'song_url' => "sound/background-music/{$e->id}/song.mp3", 'created_at' => now(), 'updated_at' => now()]);
        }
        accDelete($this, $me)->assertOk();
        Events::whereKey($due->id)->update(['purge_after' => now()->addDays(2)]);

        // Before the date: nothing is touched.
        Artisan::call('account:purge-archived');
        expect(Events::whereKey([$due->id, $later->id])->count())->toBe(2);
        Storage::disk('sweet-memories')->assertExists("images/event/{$due->id}/memories-images/a.jpg");

        $this->travel(3)->days();
        $this->artisan('account:purge-archived')->expectsOutputToContain('Purged 1 archived events')->assertSuccessful();

        expect(Events::withTrashed()->find($due->id))->toBeNull()->and(Guest::where('event_id', $due->id)->count())->toBe(0)
            ->and(DB::table('sweet_memories_images')->where('event_id', $due->id)->count())->toBe(0)
            ->and(DB::table('background_music')->where('event_id', $due->id)->count())->toBe(0)
            ->and(Events::find($later->id))->not->toBeNull()->and(Guest::where('event_id', $later->id)->count())->toBe(1)
            ->and(Events::find($other->id))->not->toBeNull();
        Storage::disk('sweet-memories')->assertMissing("images/event/{$due->id}/memories-images/a.jpg");
        Storage::disk('sweet-memories')->assertMissing("images/event/{$due->id}/memories-images/thumbs/a.jpg");
        Storage::disk('public')->assertMissing("sound/background-music/{$due->id}/song.mp3");
        Storage::disk('sweet-memories')->assertExists("images/event/{$later->id}/memories-images/a.jpg");
        Storage::disk('public')->assertExists("sound/background-music/{$later->id}/song.mp3");
        expect(AdminAuditLog::where('action', 'account.purge_archived')->sole()->after)->toMatchArray(['purged_events' => 1]);
    });

    it('is idempotent: a second run, and a manually archived event, are left alone', function () {
        $me = accUser();
        $event = accEvent($me, 'published');
        $manual = accEvent(accUser(['email' => 'otra@example.com']), 'archived', ['published_at' => now()]);
        accDelete($this, $me)->assertOk();
        $this->travel(31)->days();

        $this->artisan('account:purge-archived')->expectsOutputToContain('Purged 1')->assertSuccessful();
        $this->artisan('account:purge-archived')->expectsOutputToContain('Purged 0')->assertSuccessful();

        expect(Events::withTrashed()->find($event->id))->toBeNull()->and(Events::find($manual->id))->not->toBeNull();
        expect(AdminAuditLog::where('action', 'account.purge_archived')->count())->toBe(1);
    });

    it('is scheduled once a day', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'account:purge-archived'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 3 * * *');
    });
});

it('has the mail registered in both languages without action links', function () {
    foreach (['es', 'en'] as $lang) {
        $mail = (MailRegistry::find('account-deleted')->sample)($lang);
        $html = $mail->render();
        expect($html)->toContain($lang === 'es' ? 'Hasta pronto, Valentina' : 'See you soon, Valentina')->and($html)->not->toContain('class="button');
    }
});
