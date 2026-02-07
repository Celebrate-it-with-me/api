<?php

namespace App\Console\Commands;

use App\Models\SmsReminder;
use App\Models\User;
use Hash;
use Illuminate\Console\Command;

class ForceResetPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cwm:force-reset-password {email}';

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
        $email = $this->argument('email');

        $this->info('Checking this email in our database');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error('User with this email does not exist');
            return;
        }

        $this->info('User found: ' . $user->name);

        $newPassword = $this->secret('Please enter the new password');
        $confirmationPassword = $this->secret('Please repeat the password to confirm');

        if ($newPassword !== $confirmationPassword) {
            $this->error('Passwords do not match');
            return;
        }

        if (strlen($newPassword) < 8) {
            $this->error('Password must be at least 8 characters long');
            return;
        }

        $user->password = Hash::make($newPassword);
        $user->save();

        $this->info('Password reset successful');
    }
}
