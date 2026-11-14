<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('no longer ships Twilio or the SMS reminder command', function () {
    expect(class_exists('App\\Http\\Services\\TwilioServices'))->toBeFalse()
        ->and(class_exists('App\\Console\\Commands\\SentSmsReminder'))->toBeFalse()
        ->and(class_exists('App\\Models\\SmsReminder'))->toBeFalse()
        ->and(file_get_contents(base_path('composer.json')))->not->toContain('twilio')
        ->and(config('services.twilio'))->toBeNull()
        ->and(array_key_exists('cwm:sent-sms-reminder', Artisan::all()))->toBeFalse()
        ->and(Schema::hasTable('sms_reminders'))->toBeFalse();
});

it('does not schedule any SMS task', function () {
    $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command.' '.$e->description)->implode(' ');

    expect(strtolower($commands))->not->toContain('sms')->not->toContain('twilio');
});
