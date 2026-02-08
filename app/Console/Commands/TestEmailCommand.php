<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Test email';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        
        try {
            Mail::raw('🚀 This is a test email from Celebrate It With Me production environment.', function ($message) use ($email) {
                $message->to($email)->subject('CWM Production Email Test');
            });
            
            $this->info("Test email sent successfully to {$email}");
            return self::SUCCESS;
        } catch (Throwable $th) {
            $this->error('Email failed:' . $th->getMessage());
            return self::FAILURE;
        }
    }
}
