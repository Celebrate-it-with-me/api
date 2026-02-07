<?php

namespace App\Console\Commands;

use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Models\EventCollaborationInvite;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPendingInvitations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invites:send-initial';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send new collaboration invites that have never been sent before';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pending = EventCollaborationInvite::query()
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->get();
        
        if (!$pending->count()) {
            $this->info('No pending invitations to send.');
            return 0;
        }
        
        $pending->each(function ($invite) {
            $user = User::query()->where('email', $invite->email)->first();
            
            // Send email based on whether the user is registered or not
            Mail::to($invite->email)->send(
                $user
                    ? new InviteRegisteredUserMail($invite)
                    : new InviteUnregisteredUserMail($invite)
            );
            
            $invite->sent_at = now();
            $invite->save();
        });
        
        $this->info('Pending invitations sent successfully.');
        return 0;
    }
}
