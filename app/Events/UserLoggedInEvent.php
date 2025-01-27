<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;
use Jenssegers\Agent\Agent;

class UserLoggedInEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public Request $request;
    public array $agentData;
    
    /**
     * Create a new event instance.
     */
    public function __construct(User $user, Request $request )
    {
        $this->user = $user;
        $this->request = $request;
        $this->agentData = $this->initAgentData();
    }
    
    /**
     * Init Agent data.
     * @return array
     */
    private function initAgentData(): array
    {
        $agent = new Agent();
        
        $device = $agent->isDesktop()
            ? 'Desktop'
            : ($agent->isMobile()
                ? 'Mobile'
                : ($agent->isTablet()
                    ? 'Tablet'
                    : 'Unknown')
            );
        $browser = $agent->browser();
        $platform = $agent->platform();
        
        return [
          'browser' => $browser,
          'platform' => $platform,
          'device' => $device,
        ];
    }
    
    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [];
    }
}
