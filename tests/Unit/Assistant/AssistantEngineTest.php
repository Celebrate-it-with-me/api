<?php

use App\Http\Services\Assistant\AssistantDetectors;
use App\Http\Services\Assistant\AssistantEngine;
use App\Http\Services\Assistant\AssistantReasons;
use App\Http\Services\Assistant\AssistantResult;
use App\Http\Services\Assistant\EvaluatedTask;
use App\Http\Services\Assistant\EventFacts;
use App\Http\Services\Assistant\TaskDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\AssistantTaskSeeder;
use Database\Seeders\ModuleSeeder;

/*
 * Table tests of the pure engine: no database. The month is 30.4375 days, the same as the engine, so "10.5 months left"
 * means exactly that.
 */

const ASST_MONTH = 86400 * 30.4375;

/** @return list<TaskDefinition> */
function asstCatalog(): array
{
    $out = [];
    foreach (AssistantTaskSeeder::CATALOG as $key => $row) {
        $out[] = TaskDefinition::fromCatalogRow($key, $row);
    }

    return $out;
}

function asstNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2027-01-15 12:00:00', 'America/New_York');
}

/** Facts for an event whose date is `$monthsLeft` away and that was created `$horizon` months before its date. */
function asstFacts(float $monthsLeft = 14.0, float $horizon = 24.0, string $type = 'quinceanera', array $over = []): EventFacts
{
    $starts = asstNow()->addSeconds((int) round($monthsLeft * ASST_MONTH));
    $modules = array_fill_keys(array_keys(ModuleSeeder::CATALOG), 'active');

    return new EventFacts(...array_merge([
        'eventType' => $type,
        'startsAt' => $starts,
        'createdAt' => $starts->subSeconds((int) round($horizon * ASST_MONTH)),
        'moduleStates' => $modules,
    ], $over));
}

function asstRun(EventFacts $facts, array $states = [], ?array $catalog = null): AssistantResult
{
    return (new AssistantEngine)->evaluate($facts, $catalog ?? asstCatalog(), $states, asstNow());
}

function asstState(AssistantResult $r, string $key): ?string
{
    return $r->task($key)?->state;
}

describe('the 14 table cases of the contract', function () {
    it('1. a quinceanera 14 months out with nothing done is told to set the budget and nothing else', function () {
        $r = asstRun(asstFacts(14.0));

        expect($r->mode)->toBe('normal')
            ->and($r->stepKeys)->toBe(['budget_cap'])
            ->and(asstState($r, 'budget_cap'))->toBe('next')
            ->and(asstState($r, 'book_venue'))->toBe('upcoming')
            ->and($r->task('book_venue')->blockedBy)->toBe(['budget_cap'])
            ->and(asstState($r, 'guest_list'))->toBe('upcoming');
    });

    it('2. with the budget set, the step that unlocks most goes first and says so', function () {
        // 10.5 months: both the venue (starts at 12) and the guest list (starts at 11) are open.
        $r = asstRun(asstFacts(10.5, 24.0, 'quinceanera', ['budgetCap' => 8000.0]));

        expect(asstState($r, 'budget_cap'))->toBe('done')
            ->and($r->task('budget_cap')->doneBy)->toBe('data')
            ->and($r->stepKeys)->toBe(['book_venue', 'guest_list'])
            ->and($r->task('book_venue')->reasonCode)->toBe('unlocks')
            ->and($r->task('book_venue')->impact)->toBeGreaterThan($r->task('guest_list')->impact);
    });

    it('3. a wedding 9 months out with venue and list done offers photo and video, and never the quinceanera-only steps', function () {
        $r = asstRun(asstFacts(9.0, 24.0, 'wedding', [
            'budgetCap' => 20000.0, 'vendorCategories' => ['venue', 'ceremony'], 'peopleCount' => 60, 'mainGuests' => 40,
            'locationTypes' => ['reception'], 'padrinos' => 2,
        ]));

        expect($r->stepKeys)->toContain('book_photo_video')
            ->and($r->task('court_of_honor'))->toBeNull()
            ->and($r->task('waltz_choreography'))->toBeNull()
            ->and($r->task('book_venue')->state)->toBe('done')
            ->and($r->task('guest_list')->state)->toBe('done');
    });

    it('4. an event created close to its date is compressed: nothing is called late that was already late when she started', function () {
        // Created half a month ago for a date in 3: only 3.5 months of room.
        $r = asstRun(asstFacts(3.0, 3.5, 'quinceanera', ['budgetCap' => 5000.0]));

        $lateOnes = array_filter($r->tasks, fn (EvaluatedTask $t) => $t->definition->lateMonths > 3.0);
        expect($r->mode)->toBe('compressed')
            ->and($lateOnes)->not->toBeEmpty()
            ->and(array_filter($lateOnes, fn (EvaluatedTask $t) => $t->state === 'overdue'))->toBe([])
            ->and(count($r->stepKeys))->toBeLessThanOrEqual(3)
            ->and($r->task($r->stepKeys[0])->reasonCode)->toBe('compressed');
    });

    it('5. created 13 months before its date and 3 months away without a venue: the venue is late and comes first', function () {
        $r = asstRun(asstFacts(3.0, 13.0, 'quinceanera', ['budgetCap' => 5000.0]));

        expect($r->mode)->toBe('normal')
            ->and(asstState($r, 'book_venue'))->toBe('overdue')
            ->and($r->stepKeys[0])->toBe('book_venue')
            ->and($r->task('book_venue')->isStep)->toBeTrue();
    });

    it('6. a step whose prerequisite is not met lists what blocks it and is never next nor overdue', function () {
        $r = asstRun(asstFacts(3.0, 13.0)); // no budget cap

        $blocked = $r->task('book_venue');
        expect($blocked->blockedBy)->toBe(['budget_cap'])
            ->and($blocked->state)->toBe('available')
            ->and($r->stepKeys)->not->toContain('book_venue');

        foreach ($r->tasks as $task) {
            if ($task->blockedBy !== []) {
                expect($task->state)->not->toBe('next')->and($task->state)->not->toBe('overdue');
            }
        }
    });

    it('7. "not for me" unlocks the steps that wait for it, "hidden" does not', function () {
        $facts = asstFacts(11.0);

        $skipped = asstRun($facts, ['budget_cap' => ['state' => 'not_applicable', 'snoozed_until' => null]]);
        $hidden = asstRun($facts, ['budget_cap' => ['state' => 'hidden', 'snoozed_until' => null]]);

        expect(asstState($skipped, 'budget_cap'))->toBe('not_applicable')
            ->and($skipped->task('book_venue')->blockedBy)->toBe([])
            ->and($skipped->stepKeys)->toContain('book_venue')
            ->and(asstState($hidden, 'budget_cap'))->toBe('hidden')
            ->and($hidden->task('book_venue')->blockedBy)->toBe(['budget_cap'])
            ->and($hidden->stepKeys)->not->toContain('book_venue');
    });

    it('8. a snooze in force parks the step as upcoming; an expired one is judged again', function () {
        $facts = asstFacts(14.0);
        $future = asstNow()->addDays(10);

        $parked = asstRun($facts, ['budget_cap' => ['state' => 'snoozed', 'snoozed_until' => $future]]);
        $expired = asstRun($facts, ['budget_cap' => ['state' => 'snoozed', 'snoozed_until' => asstNow()->subDay()]]);

        expect(asstState($parked, 'budget_cap'))->toBe('upcoming')
            ->and($parked->task('budget_cap')->snoozedUntil->equalTo($future))->toBeTrue()
            ->and($parked->stepKeys)->toBe([])
            ->and(asstState($expired, 'budget_cap'))->toBe('next')
            ->and($expired->task('budget_cap')->snoozedUntil)->toBeNull();
    });

    it('9. done by her and seen by the data says user; only the data says data', function () {
        $facts = asstFacts(11.0, 24.0, 'quinceanera', ['budgetCap' => 1000.0]);

        $both = asstRun($facts, ['budget_cap' => ['state' => 'done', 'snoozed_until' => null]]);
        $data = asstRun($facts);
        $manual = asstRun(asstFacts(11.0), ['budget_cap' => ['state' => 'done', 'snoozed_until' => null]]);

        expect($both->task('budget_cap')->doneBy)->toBe('user')
            ->and($data->task('budget_cap')->doneBy)->toBe('data')
            ->and($manual->task('budget_cap')->doneBy)->toBe('user')
            ->and($data->task('book_venue')->doneBy)->toBeNull();
    });

    it('10. with everything done there are no steps, she is all set and the progress is full', function () {
        $facts = asstFacts(6.0);
        $states = [];
        foreach (asstCatalog() as $def) {
            $states[$def->key] = ['state' => 'done', 'snoozed_until' => null];
        }

        $r = asstRun($facts, $states);

        expect($r->stepKeys)->toBe([])
            ->and($r->allSet())->toBeTrue()
            ->and($r->progressDone)->toBe($r->progressTotal)
            ->and($r->ratio())->toBe(1.0);
    });

    it('11. after the date only the "afterwards" phase is offered', function () {
        $r = asstRun(asstFacts(-0.5, 12.0));

        expect($r->mode)->toBe('event_past');
        foreach ($r->stepKeys as $key) {
            expect($r->task($key)->definition->phase)->toBe('after');
        }
        expect($r->stepKeys)->not->toBe([])
            ->and($r->progressTotal)->toBe(3);
    });

    it('12. a step in a locked module is shown but never proposed nor counted', function () {
        $modules = array_fill_keys(array_keys(ModuleSeeder::CATALOG), 'active');
        $open = asstRun(asstFacts(1.0, 24.0, 'quinceanera', ['moduleStates' => $modules]));
        $locked = asstRun(asstFacts(1.0, 24.0, 'quinceanera', ['moduleStates' => ['seating' => 'locked'] + $modules]));

        // Seating waits for the follow-up of the RSVPs: mark it so the step could otherwise be offered.
        $states = ['rsvp_follow_up' => ['state' => 'done', 'snoozed_until' => null]];
        $lockedFree = asstRun(asstFacts(1.0, 24.0, 'quinceanera', ['moduleStates' => ['seating' => 'locked'] + $modules]), $states);

        expect($locked->task('seating_chart')->moduleLocked)->toBeTrue()
            ->and($lockedFree->stepKeys)->not->toContain('seating_chart')
            ->and($locked->progressTotal)->toBe($open->progressTotal - 1)
            ->and($open->task('seating_chart')->moduleLocked)->toBeFalse();
    });

    it('13. equal scores keep a stable order: phase first, then sort order', function () {
        $make = fn (string $key, string $phase, int $order) => new TaskDefinition($key, 'both', $phase, $order, 6.0, 2.0, [], null, null, '/x', $key, $key, 'why', 'why');
        $catalog = [$make('c_last', 'details', 10), $make('b_second', 'planning', 20), $make('a_first', 'planning', 10), $make('d_x', 'foundation', 50)];

        $r = asstRun(asstFacts(4.0), [], $catalog);

        // Foundation beats planning beats details, and inside planning sort order decides.
        expect($r->stepKeys)->toBe(['d_x', 'a_first', 'b_second'])
            ->and(array_map(fn (EvaluatedTask $t) => $t->score, $r->tasks))->each->toBe($r->tasks[0]->score);
    });

    it('14. the reason is translated, and a marker without a value takes its sentence with it', function () {
        $reasons = new AssistantReasons;
        $facts = asstFacts(2.0, 24.0, 'quinceanera', ['pendingGuests' => 0]);
        $r = asstRun($facts);
        $ctx = fn (EvaluatedTask $t) => ['months' => $r->monthsLeft, 'days' => $r->daysLeft, 'unlocks' => $t->impact, 'late' => $t->definition->lateMonths, 'guests' => 0, 'done' => $r->progressDone];

        foreach ($r->tasks as $task) {
            foreach (['es', 'en'] as $lang) {
                expect($reasons->render($task, $lang, $ctx($task))['reason'])->not->toContain('{')->not->toBe('');
            }
        }

        // rsvp_follow_up has "{guests}" in its text: with nobody pending the sentence is gone, with a number it is there.
        $follow = $r->task('rsvp_follow_up');
        $without = $reasons->render($follow, 'en', $ctx($follow))['reason'];
        $with = $reasons->render($follow, 'en', ['guests' => 7] + $ctx($follow))['reason'];
        expect($without)->not->toContain('haven\'t replied')->and($with)->toContain('7 guests haven\'t replied');

        $venue = asstRun(asstFacts(10.5, 24.0, 'quinceanera', ['budgetCap' => 1.0]))->task('book_venue');
        $es = $reasons->render($venue, 'es', ['months' => 10.5, 'days' => 320, 'unlocks' => 5, 'late' => 8.0, 'guests' => 0, 'done' => 1]);
        $en = $reasons->render($venue, 'en', ['months' => 10.5, 'days' => 320, 'unlocks' => 5, 'late' => 8.0, 'guests' => 0, 'done' => 1]);
        expect($es['reason'])->toContain('de aquí salen 5 cosas más')
            ->and($en['reason'])->toContain('5 other things depend on it')
            ->and($es['params'])->toBe(['unlocks' => 5]);
    });
});

describe('modes and clock', function () {
    it('has no steps without a date and none for kinds of event it does not plan', function () {
        $noDate = asstRun(new EventFacts('wedding', null, asstNow()));
        $other = asstRun(asstFacts(10.0, 24.0, 'birthday'));

        expect($noDate->mode)->toBe('no_date')->and($noDate->tasks)->toBe([])->and($noDate->stepKeys)->toBe([])
            ->and($other->mode)->toBe('unsupported')->and($other->tasks)->toBe([]);
    });

    it('measures the clock in months and days', function () {
        $r = asstRun(asstFacts(11.2, 20.0));

        expect($r->monthsLeft)->toBe(11.2)->and($r->daysLeft)->toBe((int) floor(11.2 * 30.4375))->and($r->mode)->toBe('normal');
        expect(asstRun(asstFacts(5.0, 11.9))->mode)->toBe('compressed')->and(asstRun(asstFacts(5.0, 12.0))->mode)->toBe('normal');
    });

    it('names the close-to-the-date reason inside the last month', function () {
        $r = asstRun(asstFacts(0.6, 24.0));

        expect($r->task('rehearsal')->reasonCode)->toBe('event_close');
    });

    it('skips a prerequisite that does not apply to the event instead of blocking forever', function () {
        $facts = asstFacts(9.0, 24.0, 'quinceanera', ['moduleStates' => ['guests' => 'active', 'padrinos' => 'active']]);

        // `budget` is switched off: budget_cap and the venue do not exist for this event, and nothing waits for them.
        $r = asstRun($facts);
        expect($r->task('budget_cap'))->toBeNull()->and($r->task('book_venue'))->toBeNull()
            ->and($r->task('guest_list')->blockedBy)->toBe([]);
    });
});

describe('detectors', function () {
    it('reads the facts of each rule', function (?string $detector, array $over, ?bool $expected) {
        $facts = asstFacts(6.0, 24.0, 'quinceanera', $over);

        expect((new AssistantDetectors)->evaluate($detector, $facts))->toBe($expected);
    })->with([
        'cap set' => ['budget_cap', ['budgetCap' => 1.0], true],
        'cap zero' => ['budget_cap', ['budgetCap' => 0.0], false],
        'vendor booked' => ['budget_vendor:venue', ['vendorCategories' => ['venue'], 'budgetItems' => 1], true],
        'other vendor booked' => ['budget_vendor:cake', ['vendorCategories' => ['venue'], 'budgetItems' => 1], false],
        'reception' => ['locations', ['locationTypes' => ['reception']], true],
        'only a ceremony place' => ['locations', ['locationTypes' => ['ceremony']], false],
        'list of 25 people' => ['guest_list', ['mainGuests' => 15, 'peopleCount' => 25], true],
        'list of 5 people' => ['guest_list', ['mainGuests' => 5, 'peopleCount' => 5], false],
        'no list' => ['guest_list', [], null],
        'small plan, half of it' => ['guest_list', ['mainGuests' => 8, 'peopleCount' => 10, 'planMaxGuests' => 20], true],
        'phones 80%' => ['guests_contact', ['mainGuests' => 10, 'guestsWithPhone' => 8], true],
        'phones 70%' => ['guests_contact', ['mainGuests' => 10, 'guestsWithPhone' => 7], false],
        'padrino' => ['padrinos', ['padrinos' => 1], true],
        'save the date out' => ['save_the_date', ['published' => true, 'invitationStage' => 'save_the_date'], true],
        'draft' => ['save_the_date', ['published' => false], false],
        'dress code' => ['dress_code', ['dressCode' => true], true],
        'program of 4' => ['program', ['timelineMoments' => 4], true],
        'program of 3' => ['program', ['timelineMoments' => 3], false],
        'waltz' => ['waltz', ['hasWaltz' => true], true],
        'design' => ['invitation_design', ['invitationDesign' => true], true],
        'published invitation' => ['published', ['published' => true, 'invitationStage' => 'invitation'], true],
        'published as save the date only' => ['published', ['published' => true, 'invitationStage' => 'save_the_date'], false],
        'sent to half' => ['invitations_sent', ['mainGuests' => 10, 'invitedGuests' => 5], true],
        'sent to few' => ['invitations_sent', ['mainGuests' => 10, 'invitedGuests' => 4], false],
        'deadline' => ['rsvp_deadline', ['rsvpDeadline' => true], true],
        'answers 80%' => ['rsvp_responses', ['mainGuests' => 10, 'respondedGuests' => 8], true],
        'answers none' => ['rsvp_responses', ['mainGuests' => 10, 'respondedGuests' => 0], false],
        'seated 90%' => ['seating', ['confirmedGuests' => 10, 'seatedConfirmedGuests' => 9], true],
        'nobody confirmed' => ['seating', [], null],
        'nothing pending' => ['payments_due', ['budgetItems' => 3, 'paymentsPending' => 0], true],
        'something due soon' => ['payments_due', ['budgetItems' => 3, 'paymentsPending' => 1], false],
        'no expenses at all' => ['payments_due', [], null],
        'thanks written' => ['padrinos_thanks', ['padrinos' => 2, 'padrinosThanked' => 1], true],
        'manual step' => [null, [], null],
    ]);
});

describe('reason wording', function () {
    function asstReason(string $key, string $code, string $lang, array $ctx, int $impact = 0): string
    {
        $def = TaskDefinition::fromCatalogRow($key, AssistantTaskSeeder::CATALOG[$key]);
        $task = new EvaluatedTask($def, 'next', null, [], null, false, $impact, 1.0, true, $code);

        return (new AssistantReasons)->render($task, $lang, $ctx + ['months' => 6.0, 'days' => 180, 'unlocks' => 0, 'late' => 1.0, 'guests' => 0, 'done' => 0])['reason'];
    }

    it('agrees singular, plural and zero in both languages', function (string $key, string $code, string $lang, array $ctx, string $expected, int $impact = 0) {
        expect(asstReason($key, $code, $lang, $ctx, $impact))->toContain($expected);
    })->with([
        'es 1 day' => ['rehearsal', 'event_close', 'es', ['days' => 1], 'Falta 1 día.'],
        'es 2 days' => ['rehearsal', 'event_close', 'es', ['days' => 2], 'Faltan 2 días.'],
        'en 1 day' => ['rehearsal', 'event_close', 'en', ['days' => 1], '1 day to go.'],
        'en 5 days' => ['rehearsal', 'event_close', 'en', ['days' => 5], '5 days to go.'],
        'es 1 month' => ['program', 'time_window', 'es', ['months' => 1.0], 'Falta 1 mes y este es el momento ideal.'],
        'es 1,5 months' => ['program', 'time_window', 'es', ['months' => 1.5], 'Faltan 1,5 meses'],
        'en 1.5 months' => ['program', 'time_window', 'en', ['months' => 1.5], '1.5 months to go'],
        'en 1 month' => ['program', 'time_window', 'en', ['months' => 1.0], '1 month to go'],
        'es 0 months' => ['program', 'time_window', 'es', ['months' => 0.02], 'Falta menos de un mes y este'],
        'en 0 months' => ['program', 'time_window', 'en', ['months' => 0.02], 'less than a month to go'],
        'es late 1,4' => ['book_venue', 'overdue', 'es', ['late' => 1.4], '1,4 meses antes'],
        'en late 1.4' => ['book_venue', 'overdue', 'en', ['late' => 1.4], '1.4 months out'],
        'es late 1' => ['book_venue', 'overdue', 'es', ['late' => 1.0], '1 mes antes'],
        'en late 0' => ['book_venue', 'overdue', 'en', ['late' => 0.0], 'less than a month out'],
        'es one thing' => ['program', 'unlocks', 'es', ['unlocks' => 1], 'De esto depende otra cosa.'],
        'es many things' => ['program', 'unlocks', 'es', ['unlocks' => 3], 'De esto dependen otras 3 cosas.'],
        'en one thing' => ['program', 'unlocks', 'en', ['unlocks' => 1], '1 other thing depends on this.'],
        'en many things' => ['program', 'unlocks', 'en', ['unlocks' => 3], '3 other things depend on this.'],
        'es 1 guest' => ['rsvp_follow_up', 'time_window', 'es', ['guests' => 1], 'Hay 1 invitado sin responder.'],
        'en 1 guest' => ['rsvp_follow_up', 'time_window', 'en', ['guests' => 1], '1 guest hasn\'t replied.'],
        'es unlocks in why, 1' => ['book_venue', 'unlocks', 'es', ['unlocks' => 1], 'de aquí sale 1 cosa más'],
    ]);

    it('uses one decimal convention per language, in every sentence of the same reason', function () {
        $es = asstReason('send_invitations', 'overdue', 'es', ['months' => 1.4, 'late' => 1.5]);
        $en = asstReason('send_invitations', 'overdue', 'en', ['months' => 1.4, 'late' => 1.5]);

        expect($es)->toContain('1,5 meses')->not->toContain('1.5')
            ->and($en)->toContain('1.5 months')->not->toContain('1,5');
    });

    it('never calls the same step both "just in time" and "past the best moment"', function (string $lang) {
        $text = asstReason('send_invitations', 'overdue', $lang, ['months' => 1.4, 'late' => 1.5]);

        expect($text)->not->toMatch('/tiempo justo|just enough time/u');
    })->with(['es', 'en']);
});
