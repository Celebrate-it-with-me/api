<?php

namespace App\Console\Commands;

use App\Models\SmsReminder;
use Illuminate\Console\Command;

class SentSmsReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cwm:sent-sms-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and send sms reminder notification';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $smsReminders = SmsReminder::query()
            ->where('send_date','<=', now())
            ->get();

        if ($smsReminders->count()) {
            // todo: Send sms notification.
        }
    }
}
