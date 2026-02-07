<?php

namespace App\Providers;

use App\Models\EventComment;
use App\Models\BudgetItem;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicVote;
use App\Observers\BudgetItemObserver;
use App\Observers\EventCommentObserver;
use App\Observers\EventLocationObserver;
use App\Observers\EventsObserver;
use App\Observers\GuestRsvpObserver;
use App\Observers\SaveTheDateObserver;
use App\Observers\SuggestedMusicObserver;
use App\Observers\SuggestedMusicVoteObserver;
use Illuminate\Support\ServiceProvider;

class ObserversServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Events::observe(EventsObserver::class);
        EventLocation::observe(EventLocationObserver::class);
        Guest::observe(GuestRsvpObserver::class);
        SuggestedMusic::observe(SuggestedMusicObserver::class);
        SuggestedMusicVote::observe(SuggestedMusicVoteObserver::class);
        EventComment::observe(EventCommentObserver::class);
        BudgetItem::observe(BudgetItemObserver::class);
        SaveTheDate::observe(SaveTheDateObserver::class);
    }
}
