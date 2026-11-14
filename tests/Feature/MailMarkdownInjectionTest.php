<?php

use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\User;
use App\Support\Mail\MailLocale;
use Illuminate\Mail\Markdown;

it('escapes markdown in user provided text (S-24)', function () {
    expect(MailLocale::md('[Verifica tu cuenta](https://evil.example/login)'))->toBe('\[Verifica tu cuenta\]\(https://evil.example/login\)')
        ->and(MailLocale::md('Ana *María* _x_'))->toBe('Ana \*María\* \_x\_')
        ->and(MailLocale::md(null))->toBe('');
});

it('does not turn an event name into a link inside the invitation mail', function () {
    $event = new Events(['event_name' => '[Verifica tu cuenta aquí](https://evil.example/login)']);
    $invite = new EventCollaborationInvite(['email' => 'a@b.test', 'role' => 'editor']);
    $invite->setRelation('event', $event);

    $html = (string) app(Markdown::class)->render('mail.invite-unregistered', ['invite' => $invite, 'role' => 'editor', 'acceptUrl' => 'https://app.example/accept']);

    expect($html)->not->toContain('href="https://evil.example/login"')
        ->and($html)->toContain('evil.example');
});

it('does not turn a first name into a link in the welcome mail', function () {
    $user = new User(['name' => '[click](https://evil.example)', 'email' => 'a@b.test']);

    $html = (string) app(Markdown::class)->render('mail.welcome', ['user' => $user, 'dashboardUrl' => 'https://app.example']);

    expect($html)->not->toContain('href="https://evil.example"');
});
