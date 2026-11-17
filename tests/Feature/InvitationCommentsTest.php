<?php

use App\Models\EventComment;
use App\Models\EventConfigComment;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    RateLimiter::clear('x');
});

const IC_URL = '/api/v1/app/invitation/xv-mensajes/MSG123';

/** A published event with a main guest (code MSG123) and a companion, on the given plan. */
function commentsEvent(string $plan = 'premium', array $event = []): array
{
    $event = Events::factory()->create(array_merge([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'published',
        'custom_url_slug' => 'xv-mensajes',
    ], $event));
    $main = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Rosa Fernández', 'code' => 'MSG123', 'rsvp_status' => 'pending']);
    $companion = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $main->id, 'rsvp_status' => 'pending']);

    return [$event, $main, $companion];
}

function addComment(Events $event, Guest $author, string $text, array $attributes = []): EventComment
{
    return EventComment::create(array_merge([
        'event_id' => $event->id,
        'authorable_type' => $author->getMorphClass(),
        'authorable_id' => $author->id,
        'comment' => $text,
        'status' => 'visible',
        'is_pinned' => false,
        'is_favorite' => false,
    ], $attributes));
}

describe('the comments section in the invitation', function () {
    it('carries only configuration, never the list', function () {
        [$event, $main] = commentsEvent();
        addComment($event, $main, 'Felicidades');

        $section = collect($this->getJson(IC_URL)->assertOk()->json('data.sections'))->firstWhere('type', 'comments');

        expect($section['data'])->toBe(['heading' => null, 'subHeading' => null, 'buttonText' => null, 'maxLength' => 500, 'canWrite' => true])
            ->and(json_encode($section))->not->toContain('Felicidades');
    });

    it('takes its texts from the organizer configuration and follows its changes', function () {
        [$event] = commentsEvent();
        $this->getJson(IC_URL)->assertOk(); // fills the shared cache

        EventConfigComment::create([
            'event_id' => $event->id, 'title' => 'Deja unos saludos', 'sub_title' => 'Para la quinceañera', 'background_color' => '#fff',
            'comments_title' => 'Mensajes', 'button_color' => '#000', 'button_text' => 'Enviar', 'max_comments' => 5,
        ]);

        $data = collect($this->getJson(IC_URL)->json('data.sections'))->firstWhere('type', 'comments')['data'];

        expect($data)->toMatchArray(['heading' => 'Deja unos saludos', 'subHeading' => 'Para la quinceañera', 'buttonText' => 'Enviar']);
    });

    it('is left out on a plan without the module, and its routes answer module_locked', function () {
        commentsEvent('basic');

        $types = collect($this->getJson(IC_URL)->json('data.sections'))->pluck('type')->all();
        expect($types)->not->toContain('comments');

        $this->getJson(IC_URL . '/comments')->assertForbidden()->assertJsonPath('code', 'module_locked');
        $this->postJson(IC_URL . '/comments', ['comment' => 'hola'])->assertForbidden()->assertJsonPath('code', 'module_locked');
    });

    it('is read only (canWrite false) once the owner deleted her account', function () {
        commentsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);

        $data = collect($this->getJson(IC_URL)->assertOk()->json('data.sections'))->firstWhere('type', 'comments')['data'];

        expect($data['canWrite'])->toBeFalse();
    });
});

describe('GET invitation comments', function () {
    it('lists the visible ones, pinned first, with the public shape only', function () {
        [$event, $main, $companion] = commentsEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Tía Marta', 'code' => 'OTH999']);
        addComment($event, $main, 'primero')->forceFill(['created_at' => now()->subHour()])->save();
        addComment($event, $other, 'fijado', ['is_pinned' => true])->forceFill(['created_at' => now()->subDay()])->save();
        addComment($event, $other, 'oculto por la organizadora', ['status' => 'hidden']);
        addComment($event, $other, 'oculto por el personal', ['hidden_at' => now()]);
        addComment($event, $other, 'en revision', ['status' => 'pending_review']);

        $response = $this->getJson(IC_URL . '/comments')->assertOk();

        expect(collect($response->json('data'))->pluck('comment')->all())->toBe(['fijado', 'primero'])
            ->and(array_keys($response->json('data.0')))->toBe(['id', 'author', 'comment', 'isPinned', 'createdAt'])
            ->and($response->json('data.0.author'))->toBe('Tía Marta')
            ->and($response->json('data.0.isPinned'))->toBeTrue()
            ->and($response->json('meta'))->toBe(['currentPage' => 1, 'lastPage' => 1, 'total' => 2])
            ->and($response->getContent())->not->toContain('OTH999')->not->toContain('MSG123')->not->toContain('status');
    });

    it('pages by ten', function () {
        [$event, $main] = commentsEvent();
        foreach (range(1, 12) as $i) {
            addComment($event, $main, "mensaje {$i}");
        }

        $first = $this->getJson(IC_URL . '/comments')->assertOk();
        $second = $this->getJson(IC_URL . '/comments?page=2')->assertOk();

        expect($first->json('data'))->toHaveCount(10)
            ->and($first->json('meta'))->toBe(['currentPage' => 1, 'lastPage' => 2, 'total' => 12])
            ->and($second->json('data'))->toHaveCount(2);
    });

    it('does not mix the comments of another event', function () {
        [$event, $main] = commentsEvent();
        $foreign = Events::factory()->create(['status' => 'published', 'custom_url_slug' => 'otro-evento']);
        $stranger = Guest::factory()->create(['event_id' => $foreign->id, 'parent_id' => null, 'code' => 'FOR123']);
        addComment($foreign, $stranger, 'de otro evento');

        expect($this->getJson(IC_URL . '/comments')->assertOk()->json('data'))->toBe([]);
    });

    it('answers the same 404 for a wrong code, an unknown slug, an unpublished event and a companion code', function (string $url) {
        [$event, $main, $companion] = commentsEvent();
        $companion->forceFill(['code' => 'CMP123'])->save();

        $this->getJson($url . '/comments')->assertNotFound();
        $this->postJson($url . '/comments', ['comment' => 'hola'])->assertNotFound();
    })->with([
        'wrong code' => '/api/v1/app/invitation/xv-mensajes/NOPE00',
        'unknown slug' => '/api/v1/app/invitation/no-existe/MSG123',
        'companion code' => '/api/v1/app/invitation/xv-mensajes/CMP123',
    ]);

    it('answers 404 when the event is a draft', function () {
        commentsEvent('premium', ['status' => 'draft']);

        $this->getJson(IC_URL . '/comments')->assertNotFound();
        $this->postJson(IC_URL . '/comments', ['comment' => 'hola'])->assertNotFound();
        expect(EventComment::count())->toBe(0);
    });

    it('is still readable on an archived account event', function () {
        [$event, $main] = commentsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);
        addComment($event, $main, 'recuerdo');

        $this->getJson(IC_URL . '/comments')->assertOk()->assertJsonPath('data.0.comment', 'recuerdo');
    });
});

describe('POST invitation comments', function () {
    it('publishes the comment at once under the name of the guest', function () {
        [$event, $main] = commentsEvent();

        $response = $this->postJson(IC_URL . '/comments', ['comment' => '  ¡Felicidades, Valentina!  '])->assertCreated();

        expect(array_keys($response->json('data')))->toBe(['id', 'author', 'comment', 'isPinned', 'createdAt'])
            ->and($response->json('data.author'))->toBe('Rosa Fernández')
            ->and($response->json('data.comment'))->toBe('¡Felicidades, Valentina!')
            ->and($response->json('data.isPinned'))->toBeFalse();

        $stored = EventComment::first();
        expect($stored->event_id)->toBe($event->id)
            ->and($stored->authorable_id)->toBe($main->id)
            ->and($stored->status->value)->toBe('visible');

        $this->getJson(IC_URL . '/comments')->assertJsonPath('data.0.comment', '¡Felicidades, Valentina!');
        $this->assertDatabaseHas('event_activities', ['event_id' => $event->id, 'type' => 'comment_added']);
    });

    it('validates the text with codes', function (mixed $body, string $code) {
        commentsEvent();

        $this->postJson(IC_URL . '/comments', $body)->assertStatus(422)->assertJsonPath('errors.comment.0', $code);
        expect(EventComment::count())->toBe(0);
    })->with([
        'missing' => [[], 'required'],
        'empty' => [['comment' => ''], 'required'],
        'only blanks' => [['comment' => "  \n  "], 'required'],
        '501 characters' => [['comment' => str_repeat('a', 501)], 'too_long'],
        'not text' => [['comment' => ['x']], 'invalid_characters'],
        'control characters' => [['comment' => "hola\x07"], 'invalid_characters'],
        'direction controls' => [['comment' => "hola \u{202E}atse"], 'invalid_characters'],
    ]);

    it('accepts exactly 500 characters, multibyte included', function () {
        commentsEvent();

        $this->postJson(IC_URL . '/comments', ['comment' => str_repeat('ñ', 500)])->assertCreated();
    });

    it('stores the text as plain data, never interpreted', function () {
        commentsEvent();

        $this->postJson(IC_URL . '/comments', ['comment' => '<script>alert(1)</script>'])->assertCreated()
            ->assertJsonPath('data.comment', '<script>alert(1)</script>');
    });

    it('is refused as read only once the owner deleted her account', function () {
        commentsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);

        $this->postJson(IC_URL . '/comments', ['comment' => 'hola'])->assertForbidden()->assertJsonPath('code', 'read_only');
        expect(EventComment::count())->toBe(0);
    });

    it('limits the writes of one link to 6 per 10 minutes', function () {
        commentsEvent();

        foreach (range(1, 6) as $i) {
            $this->postJson(IC_URL . '/comments', ['comment' => "mensaje {$i}"])->assertCreated();
        }

        $this->postJson(IC_URL . '/comments', ['comment' => 'el séptimo'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        expect(EventComment::count())->toBe(6);
    });

    it('limits the writes of one address to 10 per minute across links', function () {
        [$event] = commentsEvent();
        foreach (range(1, 5) as $i) {
            Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => "LNK00{$i}"]);
        }

        foreach (range(1, 10) as $i) {
            $code = 'LNK00' . (($i % 5) + 1);
            $this->postJson("/api/v1/app/invitation/xv-mensajes/{$code}/comments", ['comment' => "m{$i}"])->assertCreated();
        }

        $this->postJson('/api/v1/app/invitation/xv-mensajes/LNK001/comments', ['comment' => 'once'])->assertStatus(429);
    });

    it('does not count reads against the writing limits', function () {
        commentsEvent();

        foreach (range(1, 12) as $i) {
            $this->getJson(IC_URL . '/comments')->assertOk();
        }

        $this->postJson(IC_URL . '/comments', ['comment' => 'hola'])->assertCreated();
    });
});
