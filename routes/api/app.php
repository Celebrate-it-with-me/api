<?php

use App\Http\Controllers\AppControllers\BackgroundMusicController;
use App\Http\Controllers\AppControllers\Budget\BudgetItemController;
use App\Http\Controllers\AppControllers\Budget\EventBudgetController;
use App\Http\Controllers\AppControllers\Collaborators\InviteCollaboratorController;
use App\Http\Controllers\AppControllers\CompanionController;
use App\Http\Controllers\AppControllers\EventActivity\EventActivityController;
use App\Http\Controllers\AppControllers\EventComment\OrganizerEventCommentController;
    use App\Http\Controllers\AppControllers\EventComment\PublicEventCommentController;
    use App\Http\Controllers\AppControllers\EventCommentsController;
use App\Http\Controllers\AppControllers\EventConfigCommentsController;
use App\Http\Controllers\AppControllers\EventLocationController;
use App\Http\Controllers\AppControllers\EventPermissions\EventPermissionsController;
use App\Http\Controllers\AppControllers\EventsController;
use App\Http\Controllers\AppControllers\ExportController;
use App\Http\Controllers\AppControllers\GuestController;
use App\Http\Controllers\AppControllers\Hydrate\HydrateController;
use App\Http\Controllers\AppControllers\MenuController;
use App\Http\Controllers\AppControllers\MenuItemController;
use App\Http\Controllers\AppControllers\OrganizerRsvpController;
use App\Http\Controllers\AppControllers\PublicSuggestedMusicController;
use App\Http\Controllers\AppControllers\RsvpController;
use App\Http\Controllers\AppControllers\SaveTheDateController;
use App\Http\Controllers\AppControllers\SuggestedMusicConfigController;
use App\Http\Controllers\AppControllers\SuggestedMusicController;
use App\Http\Controllers\AppControllers\SuggestedMusicVoteController;
use App\Http\Controllers\AppControllers\SweetMemoriesConfigController;
use App\Http\Controllers\AppControllers\SweetMemoriesImageController;
use App\Http\Controllers\AppControllers\TemplateController;
use App\Http\Controllers\AppControllers\UserPreferenceController;
use App\Http\Controllers\AppControllers\UserSettingsController;
use App\Http\Controllers\AppControllers\UserTwoFAController;
use App\Http\Controllers\AuthenticationController;

// ========================================
// PUBLIC ROUTES (No Authentication)
// ========================================

// Public Event Template Routes
Route::prefix('template')->name('template.')->group(function () {
    Route::prefix('event/{event}')->name('event.')->group(function () {
        Route::get('guest/{guestCode}', [TemplateController::class, 'getEventData'])
            ->name('guest');
        Route::get('guest/{guestCode}/data', [TemplateController::class, 'getGuestData'])
            ->name('guest.data');
        Route::post('save-rsvp', [RsvpController::class, 'saveRsvp'])
            ->name('save-rsvp');
        
        Route::get('comments', [PublicEventCommentController::class, 'index'])
            ->name('comments.index');
        Route::post('comments', [PublicEventCommentController::class, 'store'])
            ->name('comments.store');
        Route::prefix('suggest-music')->name('suggest-music.')->group(function () {
            Route::get('', [PublicSuggestedMusicController::class, 'index'])->name('index');
            Route::post('', [PublicSuggestedMusicController::class, 'store'])->name('store');
            Route::delete('', [PublicSuggestedMusicController::class, 'destroy'])->name('destroy');
            
            Route::get('votes/available', [SuggestedMusicVoteController::class, 'getAvailableVotes'])
                ->name('votes.available'); // Body: { accessCode: 'ABC123' }
            Route::get('{suggestedMusic}/vote', [SuggestedMusicVoteController::class, 'getUserVote'])
                ->name('vote.show'); // Body: { accessCode: 'ABC123' }
            Route::post('{suggestedMusic}/vote', [SuggestedMusicVoteController::class, 'storeOrUpdate'])
                ->name('vote.store');
        });
    });
});

// Authentication Routes
Route::prefix('')->name('auth.')->group(function () {
    Route::post('register', [AuthenticationController::class, 'appRegister']);
    Route::post('login', [AuthenticationController::class, 'appLogin']);
    Route::post('confirm-email', [AuthenticationController::class, 'confirmEmail'])
        ->name('confirm.email')
        ->middleware('signed');
    Route::post('forgot-password', [AuthenticationController::class, 'forgotPassword'])
        ->name('forgot.password');
    Route::post('check-password-link', [AuthenticationController::class, 'checkPasswordLink'])
        ->name('check.password')
        ->middleware('signed');
    Route::post('reset-password', [AuthenticationController::class, 'resetPassword'])
        ->name('reset.password');
});



// Public Collaborator Invitation Routes
Route::get('event/{event}/collaborators/invite/{token}', [InviteCollaboratorController::class, 'checkToken'])
    ->name('collaborators.checkToken');
Route::post('event/{event}/collaborators/invite/{token}/decline', [InviteCollaboratorController::class, 'declineInvite'])
    ->name('collaborators.declineInvite');
Route::get('collaborators/check-tokens', [InviteCollaboratorController::class, 'eventTokens'])
    ->name('collaborators.eventTokens');

// ========================================
// PROTECTED ROUTES (Require Authentication)
// ========================================

Route::middleware(['auth:sanctum', 'refresh.token'])->group(function () {

    //=========================================
    // CHECK IF AUTH IS READY
    //=========================================
    Route::get('/auth/ready', [AuthenticationController::class, 'checkAuthReady'])
        ->name('auth.ready');
    
    // ========================================
    // USER MANAGEMENT ROUTES
    // ========================================
    Route::prefix('user')->name('user.')->group(function () {
        Route::get('', [UserSettingsController::class, 'getUser'])
            ->name('show');
        Route::get('hydrate/{user}', [HydrateController::class, 'hydrate'])
            ->name('hydrate');
        Route::post('update-profile', [UserSettingsController::class, 'updateProfile'])
            ->name('updateProfile');
        Route::post('update-password', [UserSettingsController::class, 'updatePassword'])
            ->name('updatePassword');

        // User Preferences
        Route::get('preferences', [UserPreferenceController::class, 'showPreferences'])
            ->name('preferences');
        Route::post('preferences', [UserPreferenceController::class, 'updatePreferences'])
            ->name('updatePreferences');

        // 2FA Routes
        Route::prefix('2fa')->name('2fa.')->group(function () {
            Route::get('setup', [UserTwoFAController::class, 'setup'])->name('setup');
            Route::get('status', [UserTwoFAController::class, 'status'])->name('status');
            Route::get('recovery-codes', [UserTwoFAController::class, 'recoveryCodes'])->name('recovery-codes');
            Route::post('enable', [UserTwoFAController::class, 'enable'])->name('enable');
            Route::post('disable', [UserTwoFAController::class, 'disable'])->name('disable');
            Route::post('verify', [UserTwoFAController::class, 'verify'])->name('verify');
        });
    });

    // ========================================
    // EVENTS MANAGEMENT ROUTES
    // ========================================
    Route::prefix('events')->name('events.')->group(function () {
        Route::get('', [EventsController::class, 'index'])->name('index');
        Route::get('load-events-plans-and-types', [EventsController::class, 'loanEventsPlansAndType'])
            ->name('loanEventsPlansAndType');
    });

    // ========================================
    // INDIVIDUAL EVENT ROUTES
    // ========================================
    Route::prefix('event')->name('event.')->group(function () {
        // Event CRUD Operations
        Route::post('', [EventsController::class, 'store'])->name('store');
        Route::get('filters', [EventsController::class, 'filterEvents'])->name('filters');
        Route::patch('active-event', [EventsController::class, 'activeEvent'])->name('active-event');

        Route::prefix('{event}')->group(function () {
            Route::prefix('suggest-music')->name('suggest-music.')->group(function () {
                Route::get('export', [SuggestedMusicController::class, 'export'])->name('export');
                Route::get('', [SuggestedMusicController::class, 'index'])->name('index');
                Route::post('', [SuggestedMusicController::class, 'store'])->name('store');
                Route::delete('{suggestedMusic}', [SuggestedMusicController::class, 'destroy'])->name('destroy');
            });

            Route::get('', [EventsController::class, 'show'])->name('show');
            Route::put('', [EventsController::class, 'update'])->name('update');
            Route::delete('', [EventsController::class, 'destroy'])->name('destroy');
            Route::get('dashboard-logs', [EventActivityController::class, 'dashboardLogs'])
                ->name('dashboard-logs');
            Route::get('permissions', [EventPermissionsController::class, 'index'])
                ->name('permissions');
            Route::get('suggestions', [EventsController::class, 'suggestions'])
                ->name('suggestions');

            // ========================================
            // COLLABORATORS
            // ========================================
            Route::prefix('collaborators')->name('collaborators.')->group(function () {
                Route::post('', [InviteCollaboratorController::class, 'invite'])->name('store');
                Route::post('invite/{id}/accept', [InviteCollaboratorController::class, 'accept'])
                    ->name('accept');
                Route::delete('{user}', [InviteCollaboratorController::class, 'destroy'])->name('destroy');
            });

            // ========================================
            // GUESTS & RSVP
            // ========================================
            Route::prefix('guests')->name('guests.')->group(function () {
                Route::get('count', [GuestController::class, 'countTotalAssistant'])->name('countTotalAssistant');
                Route::get('', [GuestController::class, 'index'])->name('index');
                Route::post('', [GuestController::class, 'store'])->name('store');
                Route::get('{guest}', [GuestController::class, 'show'])->name('show');
                Route::delete('{guest}', [GuestController::class, 'destroy'])->name('destroy');
            });

            // Organizer RSVP Management Routes
            Route::prefix('organizer/rsvp')->name('organizer.rsvp.')->group(function () {

                // Get RSVP status for modal pre-population
                Route::get('/{guest}/status', [OrganizerRsvpController::class, 'getRsvpStatus'])
                    ->name('organizer.rsvp.status');

                // Main confirmation endpoint (unified modal)
                Route::put('/{guest}/confirm', [OrganizerRsvpController::class, 'confirmRsvp'])
                    ->name('organizer.rsvp.confirm');

                // Bulk apply to companions
                Route::put('/{guest}/bulk-companions', [OrganizerRsvpController::class, 'bulkApplyToCompanions'])
                    ->name('organizer.rsvp.bulk-companions');
            });

            Route::prefix('rsvp')->name('rsvp.')->group(function () {
                Route::get('', [RsvpController::class, 'index'])->name('index');
                Route::get('summary', [RsvpController::class, 'summary'])->name('summary');
                Route::get('stats', [RsvpController::class, 'getRsvpStats'])->name('getRsvpStats');

                Route::prefix('guests')->name('guests.')->group(function () {
                    Route::get('', [RsvpController::class, 'getRsvpUsersList'])->name('list');
                    Route::get('download', [ExportController::class, 'handleExportRequest'])->name('export');
                    Route::post('{guest}/revert-confirmation', [RsvpController::class, 'revertConfirmation'])
                        ->name('revert-confirmation');
                });
            });

            // ========================================
            // SAVE THE DATE
            // ========================================
            Route::prefix('save-the-date')->name('save-the-date.')->group(function () {
                Route::get('', [SaveTheDateController::class, 'index'])->name('index');
                Route::post('', [SaveTheDateController::class, 'store'])->name('store');
            });

            // ========================================
            // LOCATION
            // ========================================
            Route::prefix('location')->name('location.')->group(function () {
                Route::get('', [EventLocationController::class, 'index'])->name('index');
                Route::post('', [EventLocationController::class, 'store'])->name('store');
                Route::get('{location}', [EventLocationController::class, 'show'])->name('show');
                Route::delete('{location}', [EventLocationController::class, 'destroy'])->name('destroy');
                Route::get('{placeId}/images', [EventLocationController::class, 'getLocationImages'])->name('images.show');
                Route::post('{location}/images', [EventLocationController::class, 'storeImages'])->name('images.store');
            });

            // ========================================
            // MENUS
            // ========================================
            Route::prefix('menus')->name('menus.')->group(function () {
                Route::get('', [MenuController::class, 'index'])->name('index');
                Route::post('', [MenuController::class, 'store'])->name('store');
                Route::get('guests', [MenuController::class, 'getGuestsMenu'])->name('guests');
                Route::get('guests/download', [ExportController::class, 'exportGuestMenuSelections'])->name('guests.export');

                Route::prefix('{menu}')->group(function () {
                    Route::get('', [MenuController::class, 'show'])->name('show');
                    Route::put('', [MenuController::class, 'update'])->name('update');
                    Route::delete('', [MenuController::class, 'destroy'])->name('destroy');

                    Route::prefix('menu-item')->name('item.')->group(function () {
                        Route::post('', [MenuItemController::class, 'store'])->name('store');
                        Route::delete('{menuItem}', [MenuItemController::class, 'destroy'])->name('destroy');
                    });
                });
            });

            // ========================================
            // BUDGET
            // ========================================
            Route::prefix('budget')->name('budget.')->group(function () {
                Route::get('', [EventBudgetController::class, 'show'])->name('show');
                Route::post('', [EventBudgetController::class, 'store'])->name('store');
                Route::put('{eventBudget}', [EventBudgetController::class, 'update'])->name('update');
                Route::delete('{eventBudget}', [EventBudgetController::class, 'destroy'])->name('destroy');

                Route::prefix('{eventBudget}/items')->group(function () {
                    Route::get('', [BudgetItemController::class, 'index'])->name('budgetItems.index');
                    Route::post('', [BudgetItemController::class, 'store'])->name('budgetItems.store');
                    Route::put('{budgetItem}', [BudgetItemController::class, 'update'])->name('budgetItems.update');
                    Route::delete('{budgetItem}', [BudgetItemController::class, 'destroy'])->name('budgetItems.destroy');
                });
            });

            // ========================================
            // MUSIC (Background & Suggestions)
            // ========================================
            Route::prefix('background-music')->name('background-music.')->group(function () {
                Route::get('', [BackgroundMusicController::class, 'index'])->name('index');
                Route::post('', [BackgroundMusicController::class, 'store'])->name('store');
            });

            Route::prefix('suggest-music-config')->name('suggest-music-config.')->group(function () {
                Route::get('', [SuggestedMusicConfigController::class, 'index'])->name('index');
                Route::post('', [SuggestedMusicConfigController::class, 'store'])->name('store');
            });

            // ========================================
            // SWEET MEMORIES
            // ========================================
            Route::prefix('sweet-memories-config')->name('sweet-memories-config.')->group(function () {
                Route::get('', [SweetMemoriesConfigController::class, 'index'])->name('index');
                Route::post('', [SweetMemoriesConfigController::class, 'store'])->name('store');
                Route::put('{sweetMemoriesConfig}', [SweetMemoriesConfigController::class, 'update'])->name('update');
            });

            Route::prefix('sweet-memories-images')->name('sweet-memories-images.')->group(function () {
                Route::get('', [SweetMemoriesImageController::class, 'index'])->name('index');
                Route::post('', [SweetMemoriesImageController::class, 'store'])->name('store');
                Route::put('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'update'])->name('update');
                Route::delete('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'destroy'])->name('destroy');
            });

            // ========================================
            // COMMENTS CONFIGURATION
            // ========================================
            Route::prefix('comments-config')->name('comments-config.')->group(function () {
                Route::get('', [EventConfigCommentsController::class, 'index'])->name('index');
                Route::post('', [EventConfigCommentsController::class, 'store'])->name('store');
                Route::put('{commentConfig}', [EventConfigCommentsController::class, 'update'])->name('update');
            });

            Route::scopeBindings()->prefix('comments')->name('comments.')->group(function() {
                Route::get('', [OrganizerEventCommentController::class, 'index'])->name('index');
                Route::get('paginated', [OrganizerEventCommentController::class, 'indexPaginated'])->name('indexPaginated');
                Route::post('', [OrganizerEventCommentController::class, 'store'])->name('store');

                Route::patch('{comment}/status', [OrganizerEventCommentController::class, 'updateStatus'])->name('status');
                Route::patch('{comment}/pin', [OrganizerEventCommentController::class, 'togglePinned'])->name('pin');
                Route::patch('{comment}/favorite', [OrganizerEventCommentController::class, 'toggleFavorite'])->name('favorite');
                Route::delete('{comment}', [OrganizerEventCommentController::class, 'destroy'])->name('destroy');
            });
        });
    });

    // ========================================
    // STANDALONE GUEST & COMPANION ROUTES (Outside event context)
    // ========================================
    Route::prefix('guest')->name('guest.')->group(function () {
        Route::patch('{guest}', [GuestController::class, 'updateCompanion'])->name('updateCompanion');
        Route::post('{guest}/companion', [CompanionController::class, 'store'])->name('storeCompanion');
    });

    Route::prefix('companion')->name('companion.')->group(function () {
        Route::put('{companion}', [CompanionController::class, 'update'])->name('update');
        Route::delete('{guestCompanion}', [CompanionController::class, 'destroy'])->name('destroy');
    });

    // ========================================
    // STANDALONE FEATURE ROUTES (Outside event context)
    // ========================================
    Route::prefix('save-the-date')->name('save-the-date.')->group(function () {
        Route::put('{saveTheDate}', [SaveTheDateController::class, 'update'])->name('update');
    });

    Route::prefix('sweet-memories-images')->name('sweet-memories-images.')->group(function () {
        Route::patch('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'updateName'])->name('update-name');
    });

    // ========================================
    // EXPORTS
    // ========================================
    Route::prefix('exports')->name('exports.')->group(function () {
        Route::get('download', [ExportController::class, 'exportsDownload'])->name('download');
    });

    // ========================================
    // LOGOUT
    // ========================================
    Route::post('logout', [AuthenticationController::class, 'appLogout'])->name('logout');
});
