<?php

use App\Http\Services\EventComment\EventCommentService;
use App\Models\AdminAuditLog;
use App\Models\EventComment;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Storage::fake('sweet-memories');
});

function modStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function modEvent(string $name = 'Los XV de Ana'): Events
{
    $event = Events::factory()->published()->create([
        'event_name' => $name,
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

function modComment(Events $event, array $attrs = [], ?Guest $guest = null): EventComment
{
    $guest ??= Guest::factory()->create(['event_id' => $event->id]);

    $comment = EventComment::create(Arr::except($attrs, ['created_at']) + [
        'event_id' => $event->id,
        'authorable_type' => $guest->getMorphClass(),
        'authorable_id' => $guest->id,
        'comment' => 'Felicidades!',
        'status' => 'visible',
    ]);
    if (isset($attrs['created_at'])) {
        $comment->forceFill(['created_at' => $attrs['created_at']])->save();
    }

    return $comment;
}

function modImage(Events $event, array $attrs = []): SweetMemoriesImage
{
    $n = SweetMemoriesImage::count() + 1;
    Storage::disk('sweet-memories')->put("images/event/{$event->id}/memories-images/p$n.jpg", 'x');
    Storage::disk('sweet-memories')->put("images/event/{$event->id}/memories-thumbnails/t$n.jpg", 'x');

    return SweetMemoriesImage::factory()->create($attrs + [
        'event_id' => $event->id,
        'image_path' => "images/event/{$event->id}/memories-images/p$n.jpg",
        'thumbnail_path' => "images/event/{$event->id}/memories-thumbnails/t$n.jpg",
    ]);
}

describe('permissions', function () {
    it('answers 401 to anonymous, 403 to normal users and lets support and superadmin in', function () {
        $event = modEvent();
        $c = modComment($event);
        $i = modImage($event);
        $routes = [
            ['getJson', '/api/v1/admin/moderation', []],
            ['postJson', "/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'spam links']],
            ['postJson', "/api/v1/admin/moderation/image/{$i->id}/restore", []],
            ['deleteJson', "/api/v1/admin/moderation/comment/{$c->id}", ['reason' => 'spam links']],
            ['postJson', '/api/v1/admin/moderation/bulk', ['action' => 'hide', 'items' => [['type' => 'comment', 'id' => $c->id]], 'reason' => 'spam links']],
        ];

        foreach ($routes as [$m, $url, $body]) {
            $this->$m($url, $body)->assertUnauthorized();
        }
        foreach ($routes as [$m, $url, $body]) {
            $this->actingAs(User::factory()->create())->$m($url, $body)->assertForbidden();
        }
        $this->actingAs(modStaff('support'))->getJson('/api/v1/admin/moderation')->assertOk();
        $this->actingAs(modStaff('superadmin'))->getJson('/api/v1/admin/moderation')->assertOk();
        $this->actingAs(modStaff('support'))->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'spam links'])->assertOk();
        $this->actingAs(modStaff('superadmin'))->postJson("/api/v1/admin/moderation/image/{$i->id}/hide", ['reason' => 'not appropriate'])->assertOk();
    });
});

describe('the queue', function () {
    it('lists comments and photos together, newest first, with the full shape', function () {
        $event = modEvent();
        $c = modComment($event, ['created_at' => now()->subDays(2)]);
        $i = modImage($event, ['created_at' => now()->subDay()]);
        $staff = modStaff('support');

        $res = $this->actingAs($staff)->getJson('/api/v1/admin/moderation')->assertOk();

        expect(collect($res->json('data'))->map(fn ($r) => $r['type'] . $r['id'])->all())->toBe(["image{$i->id}", "comment{$c->id}"])
            ->and($res->json('meta.total'))->toBe(2);
        $row = $res->json('data.1');
        expect($row)->toMatchArray(['type' => 'comment', 'id' => $c->id, 'status' => 'visible', 'hiddenAt' => null, 'hiddenBy' => null])
            ->and($row['event'])->toBe(['id' => $event->id, 'name' => 'Los XV de Ana'])
            ->and($row['author']['type'])->toBe('guest')
            ->and($row['content']['text'])->toBe('Felicidades!');
        expect($res->json('data.0.content.thumbnailUrl'))->toContain('t1.jpg');
    });

    it('filters by type, status, event, dates and search', function () {
        $a = modEvent('Los XV de Ana');
        $b = modEvent('Boda de Luis');
        $ana = Guest::factory()->create(['event_id' => $a->id, 'name' => 'Tia Rosario']);
        $old = modComment($a, ['created_at' => '2026-01-10 10:00:00', 'comment' => 'hola viejo'], $ana);
        modComment($a, ['status' => 'hidden', 'comment' => 'oculto', 'hidden_at' => now()]);
        modComment($b, ['comment' => 'en la boda']);
        modImage($b, ['hidden_at' => now(), 'title' => 'Foto sospechosa']);
        modImage($a);
        $staff = modStaff('support');
        $get = fn (string $q) => $this->actingAs($staff)->getJson('/api/v1/admin/moderation?' . $q)->assertOk()->json('data');

        expect($get('type=image'))->toHaveCount(2)
            ->and($get('type=comment'))->toHaveCount(3)
            ->and($get('status=hidden'))->toHaveCount(2)
            ->and($get('status=visible'))->toHaveCount(3)
            ->and($get("eventId={$b->id}"))->toHaveCount(2)
            ->and($get('from=2026-01-10&to=2026-01-10'))->toHaveCount(1)
            ->and($get('search=viejo')[0]['id'])->toBe($old->id)
            ->and($get('search=Rosario'))->toHaveCount(1)
            ->and($get('search=Boda'))->toHaveCount(2)
            ->and($get('search=sospechosa'))->toHaveCount(1);
        $this->actingAs($staff)->getJson('/api/v1/admin/moderation?type=video')->assertUnprocessable();
        $this->actingAs($staff)->getJson('/api/v1/admin/moderation?status=pending')->assertUnprocessable();
    });

    it('paginates and does not run a query per row', function () {
        $event = modEvent();
        $guest = Guest::factory()->create(['event_id' => $event->id]);
        foreach (range(1, 6) as $_) {
            modComment($event, [], $guest);
            modImage($event);
        }
        $staff = modStaff('support');
        $count = function (string $q) use ($staff) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($staff)->getJson('/api/v1/admin/moderation?' . $q)->assertOk();

            return count(DB::getQueryLog());
        };

        $count('perPage=5'); // warm up auth/permission caches
        $small = $count('perPage=5');
        $big = $count('perPage=12');
        $res = $this->actingAs($staff)->getJson('/api/v1/admin/moderation?perPage=5&page=2')->assertOk();

        expect($big)->toBe($small)->and($res->json('meta'))->toBe(['page' => 2, 'perPage' => 5, 'total' => 12, 'lastPage' => 3]);
    });

    it('shows who hid an item and when', function () {
        $event = modEvent();
        $c = modComment($event);
        $staff = modStaff('support');
        $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'spam links'])->assertOk();

        $row = $this->actingAs($staff)->getJson('/api/v1/admin/moderation?status=hidden')->json('data.0');

        expect($row['hiddenBy'])->toBe(['id' => $staff->id, 'name' => $staff->name])->and($row['hiddenAt'])->not->toBeNull();
    });
});

describe('what guests and organizers see', function () {
    it('drops hidden comments from the public and organizer outputs and keeps the visible ones', function () {
        $event = modEvent();
        $ok = modComment($event, ['comment' => 'se ve']);
        $bad = modComment($event, ['comment' => 'se oculta']);
        $byOrganizer = modComment($event, ['comment' => 'oculto por la organizadora', 'status' => 'hidden']);
        $this->actingAs(modStaff('support'))->postJson("/api/v1/admin/moderation/comment/{$bad->id}/hide", ['reason' => 'spam links'])->assertOk();
        app('auth')->forgetGuards();
        $guest = Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001']);
        $public = $this->getJson("/api/v1/app/template/event/{$event->id}/comments?accessCode=VAL001")->assertOk();
        expect(collect($public->json('data'))->pluck('id')->all())->toBe([$ok->id]);

        $owner = User::find(EventUserRole::where('event_id', $event->id)->value('user_id'));
        // (The organizer's unpaginated list has an old bug: scopePinned() ignores its argument. Not touched: frozen module.)
        $ids = collect($this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/comments/paginated")->assertOk()->json('data'))->pluck('id')->all();
        expect($ids)->toContain($ok->id, $byOrganizer->id)->not->toContain($bad->id);
        $service = app(EventCommentService::class);
        expect($service->listForEvent($event)->pluck('id')->all())->not->toContain($bad->id);
    });

    it('keeps a staff-hidden comment hidden when the organizer tries to restore it', function () {
        $event = modEvent();
        $c = modComment($event);
        $this->actingAs(modStaff('support'))->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'spam links'])->assertOk();
        $ownerUser = User::find(EventUserRole::where('event_id', $event->id)->value('user_id'));

        $this->actingAs($ownerUser)->patchJson("/api/v1/app/event/{$event->id}/comments/{$c->id}/status", ['status' => 'visible'])->assertForbidden();
        expect($c->fresh()->status->value)->toBe('hidden');
    });

    it('drops hidden photos from the event relation, the organizer list and the counts, and keeps visible ones', function () {
        $event = modEvent();
        $ok = modImage($event);
        $bad = modImage($event);
        $this->actingAs(modStaff('support'))->postJson("/api/v1/admin/moderation/image/{$bad->id}/hide", ['reason' => 'not appropriate'])->assertOk();

        expect($event->fresh()->sweetMemoriesImages->pluck('id')->all())->toBe([$ok->id])
            ->and($event->fresh()->sweetMemoriesImages()->count())->toBe(1);

        $ownerUser = User::find(EventUserRole::where('event_id', $event->id)->value('user_id'));
        $res = $this->actingAs($ownerUser)->getJson("/api/v1/app/event/{$event->id}/sweet-memories-images")->assertOk();
        expect(collect($res->json('data'))->pluck('id')->all())->toBe([$ok->id]);
        // Restoring brings it back.
        $this->actingAs(modStaff('support'))->postJson("/api/v1/admin/moderation/image/{$bad->id}/restore")->assertOk();
        expect($event->fresh()->sweetMemoriesImages()->count())->toBe(2);
    });
});

describe('actions', function () {
    it('hides, restores and audits a comment, with the event', function () {
        $event = modEvent();
        $c = modComment($event);
        $staff = modStaff('support');

        $hide = $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'insultos'])->assertOk();
        expect($hide->json('data.changed'))->toBeTrue()->and($hide->json('data.item.status'))->toBe('hidden')
            ->and($c->fresh()->hidden_by)->toBe($staff->id);
        $log = AdminAuditLog::where('action', 'moderation.hide')->firstOrFail();
        expect($log->event_id)->toBe($event->id)->and($log->reason)->toBe('insultos')->and($log->actor_id)->toBe($staff->id)
            ->and($log->subject_type)->toBe('comment')->and($log->subject_id)->toBe($c->id);

        // Hiding twice changes nothing and writes nothing.
        $again = $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'insultos'])->assertOk();
        expect($again->json('data.changed'))->toBeFalse()->and(AdminAuditLog::where('action', 'moderation.hide')->count())->toBe(1);

        $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/restore")->assertOk()->assertJsonPath('data.item.status', 'visible');
        expect($c->fresh()->hidden_at)->toBeNull()->and($c->fresh()->status->value)->toBe('visible')
            ->and(AdminAuditLog::where('action', 'moderation.restore')->where('event_id', $event->id)->exists())->toBeTrue();
    });

    it('asks for a reason to hide and delete but not to restore', function () {
        $event = modEvent();
        $c = modComment($event);
        $staff = modStaff('support');

        $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/hide", ['reason' => 'abc'])->assertUnprocessable();
        $this->actingAs($staff)->deleteJson("/api/v1/admin/moderation/comment/{$c->id}")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'hide', 'items' => [['type' => 'comment', 'id' => $c->id]]])->assertUnprocessable();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'delete', 'items' => [['type' => 'comment', 'id' => $c->id]]])->assertUnprocessable();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'restore', 'items' => [['type' => 'comment', 'id' => $c->id]]])->assertOk();
        $this->actingAs($staff)->postJson("/api/v1/admin/moderation/comment/{$c->id}/restore")->assertOk();
        expect(AdminAuditLog::count())->toBe(0);
    });

    it('answers 404 for a bad type or a missing item', function () {
        $staff = modStaff('support');

        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/video/1/hide', ['reason' => 'spam links'])->assertNotFound();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/comment/9999/hide', ['reason' => 'spam links'])->assertNotFound();
        $this->actingAs($staff)->deleteJson('/api/v1/admin/moderation/image/9999', ['reason' => 'spam links'])->assertNotFound();
    });

    it('deletes a photo with both of its files and audits it', function () {
        $event = modEvent();
        $img = modImage($event);
        $paths = [$img->image_path, $img->thumbnail_path];
        Storage::disk('sweet-memories')->assertExists($paths);

        $this->actingAs(modStaff('support'))->deleteJson("/api/v1/admin/moderation/image/{$img->id}", ['reason' => 'contenido ofensivo'])
            ->assertOk()->assertJsonPath('data.item', null);

        Storage::disk('sweet-memories')->assertMissing($paths);
        expect(SweetMemoriesImage::find($img->id))->toBeNull();
        $log = AdminAuditLog::where('action', 'moderation.delete')->firstOrFail();
        expect($log->event_id)->toBe($event->id)->and($log->subject_type)->toBe('image')->and($log->reason)->toBe('contenido ofensivo');
    });

    it('keeps a file that another photo still uses', function () {
        $event = modEvent();
        $a = modImage($event);
        $b = modImage($event, ['image_path' => $a->image_path]);

        $this->actingAs(modStaff('support'))->deleteJson("/api/v1/admin/moderation/image/{$b->id}", ['reason' => 'contenido ofensivo'])->assertOk();

        Storage::disk('sweet-memories')->assertExists($a->image_path);
        Storage::disk('sweet-memories')->assertMissing($b->thumbnail_path);
    });

    it('soft deletes a comment like the organizer does', function () {
        $c = modComment(modEvent());

        $this->actingAs(modStaff('support'))->deleteJson("/api/v1/admin/moderation/comment/{$c->id}", ['reason' => 'contenido ofensivo'])->assertOk();

        expect(EventComment::find($c->id))->toBeNull()->and(EventComment::withTrashed()->find($c->id))->not->toBeNull();
    });
});

describe('bulk', function () {
    it('applies the action to each item and audits one row per item', function () {
        $a = modEvent('Evento A');
        $b = modEvent('Evento B');
        $c1 = modComment($a);
        $c2 = modComment($b);
        $i1 = modImage($a);

        $res = $this->actingAs(modStaff('support'))->postJson('/api/v1/admin/moderation/bulk', [
            'action' => 'hide',
            'items' => [['type' => 'comment', 'id' => $c1->id], ['type' => 'comment', 'id' => $c2->id], ['type' => 'image', 'id' => $i1->id]],
            'reason' => 'spam masivo',
        ])->assertOk();

        expect($res->json('data.succeeded'))->toBe(3)->and($res->json('data.failed'))->toBe(0)
            ->and(AdminAuditLog::where('action', 'moderation.hide')->pluck('event_id')->sort()->values()->all())->toBe(collect([$a->id, $a->id, $b->id])->sort()->values()->all());
    });

    it('reports the failures and still applies the rest (partial batch)', function () {
        $event = modEvent();
        $ok = modComment($event);
        $img = modImage($event);

        $res = $this->actingAs(modStaff('support'))->postJson('/api/v1/admin/moderation/bulk', [
            'action' => 'hide',
            'items' => [['type' => 'comment', 'id' => 9999], ['type' => 'comment', 'id' => $ok->id], ['type' => 'comment', 'id' => $ok->id], ['type' => 'image', 'id' => $img->id]],
            'reason' => 'spam masivo',
        ])->assertOk();

        expect($res->json('data.succeeded'))->toBe(2)->and($res->json('data.failed'))->toBe(1)
            ->and($res->json('data.results'))->toHaveCount(3)
            ->and($res->json('data.results.0'))->toBe(['type' => 'comment', 'id' => 9999, 'ok' => false, 'changed' => false, 'error' => ['code' => 'not_found', 'message' => 'El elemento ya no existe.']])
            ->and($ok->fresh()->hidden_at)->not->toBeNull()->and($img->fresh()->hidden_at)->not->toBeNull()
            ->and(AdminAuditLog::where('action', 'moderation.hide')->count())->toBe(2);
    });

    it('rolls back only the failing item when its photo files cannot be deleted', function () {
        $event = modEvent();
        $good = modComment($event);
        $img = modImage($event);
        Storage::shouldReceive('disk')->with('sweet-memories')->andReturn(
            tap(Mockery::mock(Filesystem::class), fn ($m) => $m->shouldReceive('delete')->andThrow(new RuntimeException('disk down')))
        );

        $res = $this->actingAs(modStaff('support'))->postJson('/api/v1/admin/moderation/bulk', [
            'action' => 'delete',
            'items' => [['type' => 'image', 'id' => $img->id], ['type' => 'comment', 'id' => $good->id]],
            'reason' => 'contenido ofensivo',
        ])->assertOk();

        expect($res->json('data.results.0.error.code'))->toBe('storage_failed')->and($res->json('data.results.1.ok'))->toBeTrue()
            ->and(SweetMemoriesImage::find($img->id))->not->toBeNull()->and(EventComment::find($good->id))->toBeNull()
            ->and(AdminAuditLog::where('action', 'moderation.delete')->count())->toBe(1);
    });

    it('validates the batch: at most 100, known types, and an action', function () {
        $staff = modStaff('support');
        $items = fn (int $n) => array_map(fn ($i) => ['type' => 'comment', 'id' => $i], range(1, $n));

        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'hide', 'items' => $items(101), 'reason' => 'spam masivo'])->assertUnprocessable();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'hide', 'items' => [], 'reason' => 'spam masivo'])->assertUnprocessable();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'nuke', 'items' => $items(1), 'reason' => 'spam masivo'])->assertUnprocessable();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'hide', 'items' => [['type' => 'video', 'id' => 1]], 'reason' => 'spam masivo'])->assertUnprocessable();
        $this->actingAs($staff)->postJson('/api/v1/admin/moderation/bulk', ['action' => 'hide', 'items' => $items(100), 'reason' => 'spam masivo'])->assertOk()->assertJsonPath('data.failed', 100);
    });
});
