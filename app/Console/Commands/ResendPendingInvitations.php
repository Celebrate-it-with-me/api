<?php

namespace App\Console\Commands;

use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Models\EventCollaborationInvite;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ResendPendingInvitations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invites:resend';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resend pending collaboration invites that were previously sent';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $resendLimit = 3;
        
        $pending = EventCollaborationInvite::where('status', 'pending')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', now()->subDay())
            ->where('resend_count', '<', $resendLimit)
            ->get();
        
        if (!$pending->count()) {
            $this->info('No pending invitations to send.');
            return 0;
        }
        
        $pending->each(function ($invite) use ($resendLimit) {
            $user = User::where('email', $invite->email)->first();
            
            // Send email based on whether the user is registered or not
            Mail::to($invite->email)->send(
                $user
                    ? new InviteRegisteredUserMail($invite)
                    : new InviteUnregisteredUserMail($invite)
            );
            
            // Update the invite details
            $invite->sent_at = now();
            $invite->resend_count += 1;
            $invite->save();
        });
        
        $this->info('Pending invitations sent successfully.');
        return 0;
    }
}
