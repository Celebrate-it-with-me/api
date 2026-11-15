<?php

namespace App\Console\Commands;

use App\Models\EventCollaborationInvite;
use Illuminate\Console\Command;

class ExpireCollaborationInvites extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invites:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark expired collaboration invites as declined';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expired = EventCollaborationInvite::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $expired->each(function ($invite) {
            $invite->status = 'declined';
            $invite->save();
        });

        $this->info('Pending invitations sent successfully.');

        return 0;
    }
}
