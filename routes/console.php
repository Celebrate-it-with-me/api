<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for the template review status (Meta also pushes it through the webhook).
Schedule::command('whatsapp:sync-templates')->hourly()->withoutOverlapping();

// Events kept 30 days after their owner deleted her account are removed for good (docs/plans/account-deletion-contract.md).
Schedule::command('account:purge-archived')->dailyAt('03:30')->withoutOverlapping();

// Uploads of template packages that nobody installed are removed after their lifetime (24 h by default).
Schedule::command('templates:purge-stages')->hourly()->withoutOverlapping();
Schedule::command('templates:verify-packages')->dailyAt('04:30')->withoutOverlapping();

// A webhook that never arrived must not leave a paid checkout pending (docs/plans/stripe-checkout-contract-draft.md, §6).
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();

// Tables that only grow (webhook keys, orphan WhatsApp statuses, payment events, ended impersonations, dead admin tokens, failed jobs): config/retention.php.
Schedule::command('data:prune')->dailyAt('02:30')->withoutOverlapping();
// Expired password reset tokens (the broker's own expiry makes them useless; this only empties the table).
Schedule::command('auth:clear-resets')->dailyAt('02:45');
