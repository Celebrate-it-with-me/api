<?php
    
    use App\Http\Controllers\AppControllers\BackgroundMusicController;
    use App\Http\Controllers\AppControllers\Budget\BudgetController;
    use App\Http\Controllers\AppControllers\Collaborators\InviteCollaboratorController;
    use App\Http\Controllers\AppControllers\EventActivity\EventActivityController;
    use App\Http\Controllers\AppControllers\EventComment\OrganizerEventCommentController;
    use App\Http\Controllers\AppControllers\EventComment\PublicEventCommentController;
    use App\Http\Controllers\AppControllers\EventConfigCommentsController;
    use App\Http\Controllers\AppControllers\EventLocationController;
    use App\Http\Controllers\AppControllers\EventLifecycleController;
    use App\Http\Controllers\AppControllers\EventPermissions\EventPermissionsController;
    use App\Http\Controllers\AppControllers\EventsController;
    use App\Http\Controllers\AppControllers\ExportController;
    use App\Http\Controllers\AppControllers\GuestController;
    use App\Http\Controllers\AppControllers\GuestExportController;
    use App\Http\Controllers\AppControllers\GuestImportController;
    use App\Http\Controllers\AppControllers\GuestRosterController;
    use App\Http\Controllers\AppControllers\Hydrate\HydrateController;
    use App\Http\Controllers\AppControllers\MenuController;
    use App\Http\Controllers\AppControllers\MenuItemController;
    use App\Http\Controllers\AppControllers\OnboardingController;
    use App\Http\Controllers\AppControllers\OrganizerRsvpController;
    use App\Http\Controllers\AppControllers\PadrinoController;
    use App\Http\Controllers\AppControllers\PublicSuggestedMusicController;
    use App\Http\Controllers\AppControllers\RsvpController;
    use App\Http\Controllers\AppControllers\SaveTheDate\SaveTheDateController;
    use App\Http\Controllers\AppControllers\Seating\TableController;
    use App\Http\Controllers\AppControllers\SuggestedMusicConfigController;
    use App\Http\Controllers\AppControllers\SuggestedMusicController;
    use App\Http\Controllers\AppControllers\SuggestedMusicVoteController;
    use App\Http\Controllers\AppControllers\SweetMemoriesConfigController;
    use App\Http\Controllers\AppControllers\SweetMemoriesImageController;
    use App\Http\Controllers\AppControllers\Timeline\TimelineController;
    use App\Http\Controllers\AppControllers\UserPreferenceController;
    use App\Http\Controllers\AppControllers\UserSettingsController;
    use App\Http\Controllers\AppControllers\UserTwoFAController;
    use App\Http\Controllers\AuthenticationController;
    use App\Http\Controllers\DressCodeController;
    use App\Http\Controllers\AppControllers\PublicInvitationController;

// ========================================
// PUBLIC ROUTES (No Authentication)
// ========================================

// Public invitation: event slug + guest code are the only credentials.
Route::prefix('invitation/{slug}/{code}')->name('invitation.')->group(function () {
    Route::get('', [PublicInvitationController::class, 'show'])->middleware('throttle:60,1')->name('show');
    Route::post('rsvp', [PublicInvitationController::class, 'rsvp'])->middleware(['throttle:20,1', 'module.access:rsvp'])->name('rsvp');
    Route::get('calendar.ics', [PublicInvitationController::class, 'calendar'])->middleware('throttle:30,1')->name('calendar');
});

// Public Event Template Routes
Route::prefix('template')->name('template.')->group(function () {
    // Guest-facing: a valid access code of this event is required, even to read (see EnsureGuestEventAccess).
    Route::prefix('event/{event}')->middleware(['event.guest', 'throttle:60,1'])->name('event.')->group(function () {
        Route::get('comments', [PublicEventCommentController::class, 'index'])
            ->middleware('module.access:comments')->name('comments.index');
        Route::post('comments', [PublicEventCommentController::class, 'store'])
            ->middleware('module.access:comments')->name('comments.store');
        Route::prefix('suggest-music')->name('suggest-music.')->middleware('module.access:suggested_music')->group(function () {
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
Route::post('payments/webhook', [\App\Http\Controllers\Payments\PurchaseController::class, 'webhook'])->middleware('throttle:120,1')->name('payments.webhook');

// "Mis compras" (invoices): its own file, own auth middleware. Keep it above the group that ends the impersonation
// session: ImpersonationBlocksTest walks the routes in order and that last route revokes the token.
require __DIR__ . '/app/invoices.php';

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

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token'])->group(function () {

    //=========================================
    // CHECK IF AUTH IS READY
    //=========================================
    Route::get('/auth/ready', [AuthenticationController::class, 'checkAuthReady'])
        ->name('auth.ready');

    // "Necesito ayuda": the organizer's own support requests.
    Route::get('support', [\App\Http\Controllers\AppControllers\Support\SupportRequestController::class, 'index'])->name('support.index');
    Route::post('support', [\App\Http\Controllers\AppControllers\Support\SupportRequestController::class, 'store'])->middleware('throttle:5,1')->name('support.store');
    
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
            Route::post('recovery-codes', [UserTwoFAController::class, 'regenerateRecoveryCodes'])->name('recovery-codes.regenerate');
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

        Route::prefix('{event}')->middleware('event.access')->group(function () {
            Route::prefix('suggest-music')->name('suggest-music.')->middleware('module.access:suggested_music')->group(function () {
                Route::get('export', [SuggestedMusicController::class, 'export'])->name('export');
                Route::get('', [SuggestedMusicController::class, 'index'])->name('index');
                Route::post('', [SuggestedMusicController::class, 'store'])->name('store');
                Route::delete('{suggestedMusic}', [SuggestedMusicController::class, 'destroy'])->name('destroy');
            });

            Route::get('', [EventsController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '', [EventsController::class, 'update'])->name('update');
            Route::prefix('purchase')->name('purchase.')->middleware('event.access:delete_event')->group(function () {
                Route::get('quote', [\App\Http\Controllers\Payments\PurchaseController::class, 'quote'])->name('quote');
                Route::post('checkout', [\App\Http\Controllers\Payments\PurchaseController::class, 'checkout'])->name('checkout');
                Route::get('status', [\App\Http\Controllers\Payments\PurchaseController::class, 'status'])->name('status');
            });
            Route::post('publish', [EventLifecycleController::class, 'publish'])->name('publish');
            Route::post('unpublish', [EventLifecycleController::class, 'unpublish'])->name('unpublish');
            Route::post('archive', [EventLifecycleController::class, 'archive'])->name('archive');
            Route::post('restore', [EventLifecycleController::class, 'restore'])->name('restore');
            Route::delete('', [EventsController::class, 'destroy'])->middleware('event.access:delete_event')->name('destroy');
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
                Route::post('', [InviteCollaboratorController::class, 'invite'])
                    ->middleware('event.access:manage_collaborators')->name('store');
                // The invitee is not a member yet; the controller checks the invite belongs to their email.
                Route::post('invite/{id}/accept', [InviteCollaboratorController::class, 'accept'])
                    ->withoutMiddleware('event.access')->name('accept');
                Route::delete('{user}', [InviteCollaboratorController::class, 'destroy'])
                    ->middleware('event.access:manage_collaborators')->name('destroy');
            });

            // ========================================
            // GUESTS & RSVP
            // ========================================
            Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');

            // The unified "Invitados" screen: status tabs, event-wide counters, edit people.
            Route::prefix('roster')->name('roster.')->middleware('module.access:guests')->group(function () {
                Route::get('', [GuestRosterController::class, 'index'])->name('index');
                Route::get('import/template', [GuestImportController::class, 'template'])->name('import.template');
                Route::post('import/preview', [GuestImportController::class, 'preview'])->name('import.preview');
                Route::post('import', [GuestImportController::class, 'store'])->name('import.store');
                Route::get('export', [GuestExportController::class, 'roster'])->middleware('throttle:10,1')->name('export');
                Route::get('export/venue', [GuestExportController::class, 'venue'])->middleware('throttle:10,1')->name('export.venue');
                Route::put('{guest}', [GuestRosterController::class, 'update'])->name('update');
                Route::patch('{guest}/locale', [GuestRosterController::class, 'updateLocale'])->name('locale');
                Route::post('{guest}/companions', [GuestRosterController::class, 'storeCompanion'])->name('companions.store');
                Route::delete('{guest}', [GuestRosterController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('guests')->name('guests.')->middleware('module.access:guests')->group(function () {
                Route::post('', [GuestController::class, 'store'])->name('store');
            });

            // Organizer RSVP Management Routes
            Route::prefix('organizer/rsvp')->name('organizer.rsvp.')->middleware('module.access:rsvp')->group(function () {

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

            Route::prefix('rsvp')->name('rsvp.')->middleware('module.access:rsvp')->group(function () {
                Route::get('stats', [RsvpController::class, 'getRsvpStats'])->name('getRsvpStats');

                Route::prefix('guests')->name('guests.')->group(function () {
                    Route::post('{guest}/revert-confirmation', [RsvpController::class, 'revertConfirmation'])
                        ->name('revert-confirmation');
                });
            });

            // ========================================
            // SAVE THE DATE
            // ========================================
            Route::prefix('save-the-date')->name('save-the-date.')->middleware('module.access:save_the_date')->group(function () {
                Route::get('', [SaveTheDateController::class, 'show'])->name('index');
                Route::put('', [SaveTheDateController::class, 'upsert'])->name('upsert');
                Route::delete('', [SaveTheDateController::class, 'destroy'])->name('destroy');
            });

            // ========================================
            // DRESS CODE
            // ========================================
            Route::prefix('dress-code')->name('dress-code.')->middleware('module.access:dress_code')->group(function () {
                Route::get('', [DressCodeController::class, 'index'])->name('index');
                Route::post('', [DressCodeController::class, 'store'])->name('store'); // create or replace
                Route::delete('', [DressCodeController::class, 'destroy'])->name('destroy');
            });
            
            // ========================================
            // Timeline
            // ========================================
            Route::prefix('timeline')->name('timeline.')->middleware('module.access:timeline')->group(function () {
                Route::get('', [TimelineController::class, 'index'])->name('index');
                Route::post('typical', [TimelineController::class, 'typical'])->name('typical');
                Route::post('', [TimelineController::class, 'store'])->name('store');
                Route::put('{timeline}', [TimelineController::class, 'update'])->name('update');
                Route::delete('{timeline}', [TimelineController::class, 'destroy'])->name('destroy');
            });
            
            
            // ========================================
            // Seating
            // ========================================
                // Tables
            Route::prefix('tables')->name('tables.')->middleware('module.access:seating')->group(function() {
                Route::get('', [TableController::class, 'index'])->name('index');
                Route::post('', [TableController::class, 'store'])->name('store');
                Route::get('{table}', [TableController::class, 'show'])->name('show');
                Route::put('{table}', [TableController::class, 'update'])->name('update');
                Route::delete('{table}', [TableController::class, 'destroy'])->name('destroy');
                
                Route::post('{table}/assign', [TableController::class, 'assignGuest'])->name('assign-guests');
                Route::delete('{table}/guest/{guest}', [TableController::class, 'removeGuest'])->name('remove-guest');
            });
            
                // Seating
            Route::prefix('seating')->name('seating.')->middleware('module.access:seating')->group(function() {
                Route::get('unassigned', [TableController::class, 'unassignedGuests'])->name('unassigned-guests');
                Route::post('bulk-assign', [TableController::class, 'bulkAssign'])->name('bulk-assign');
                Route::delete('clear-all', [TableController::class, 'clearAll'])->name('clear-all');
            });
            
            
            // ========================================
            // LOCATION
            // ========================================
            Route::prefix('location')->name('location.')->middleware('module.access:locations')->group(function () {
                Route::get('', [EventLocationController::class, 'index'])->name('index');
                Route::post('', [EventLocationController::class, 'store'])->name('store');
                Route::get('{location}', [EventLocationController::class, 'show'])->name('show');
                Route::put('{location}', [EventLocationController::class, 'update'])->name('update');
                Route::delete('{location}', [EventLocationController::class, 'destroy'])->name('destroy');
                Route::get('{placeId}/images', [EventLocationController::class, 'getLocationImages'])->name('images.show');
                Route::post('{location}/images', [EventLocationController::class, 'storeImages'])->name('images.store');
                Route::delete('{location}/images/{image}', [EventLocationController::class, 'destroyImage'])->name('images.destroy');
            });

            // ========================================
            // MENUS
            // ========================================
            Route::prefix('menus')->name('menus.')->middleware('module.access:menus')->group(function () {
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
            Route::prefix('budget')->name('budget.')->middleware('module.access:budget')->group(function () {
                Route::get('', [BudgetController::class, 'show'])->name('show');
                Route::put('', [BudgetController::class, 'update'])->name('update');
                Route::get('export', [BudgetController::class, 'export'])->name('export');

                Route::post('categories', [BudgetController::class, 'storeCategory'])->name('categories.store');
                Route::put('categories/{category}', [BudgetController::class, 'updateCategory'])->name('categories.update');
                Route::delete('categories/{category}', [BudgetController::class, 'destroyCategory'])->name('categories.destroy');

                Route::get('items', [BudgetController::class, 'items'])->name('items.index');
                Route::post('items', [BudgetController::class, 'storeItem'])->name('items.store');
                Route::put('items/{item}', [BudgetController::class, 'updateItem'])->name('items.update');
                Route::delete('items/{item}', [BudgetController::class, 'destroyItem'])->name('items.destroy');

                Route::post('items/{item}/payments', [BudgetController::class, 'storePayment'])->name('payments.store');
                Route::put('items/{item}/payments/{payment}', [BudgetController::class, 'updatePayment'])->name('payments.update');
                Route::delete('items/{item}/payments/{payment}', [BudgetController::class, 'destroyPayment'])->name('payments.destroy');
            });

            // ========================================
            // PADRINOS
            // ========================================
            Route::prefix('padrinos')->name('padrinos.')->middleware('module.access:padrinos')->group(function () {
                Route::get('', [PadrinoController::class, 'index'])->name('index');
                Route::post('', [PadrinoController::class, 'store'])->name('store');
                Route::put('{padrino}', [PadrinoController::class, 'update'])->name('update');
                Route::delete('{padrino}', [PadrinoController::class, 'destroy'])->name('destroy');
                Route::put('{padrino}/commitments', [PadrinoController::class, 'setCommitments'])->name('commitments');
            });

            // ========================================
            // MUSIC (Background & Suggestions)
            // ========================================
            Route::prefix('background-music')->name('background-music.')->group(function () {
                Route::get('', [BackgroundMusicController::class, 'index'])->name('index');
                Route::post('', [BackgroundMusicController::class, 'store'])->name('store');
            });

            Route::prefix('suggest-music-config')->name('suggest-music-config.')->middleware('module.access:suggested_music')->group(function () {
                Route::get('', [SuggestedMusicConfigController::class, 'index'])->name('index');
                Route::post('', [SuggestedMusicConfigController::class, 'store'])->name('store');
            });

            // ========================================
            // SWEET MEMORIES
            // ========================================
            Route::prefix('sweet-memories-config')->name('sweet-memories-config.')->middleware('module.access:memories')->group(function () {
                Route::get('', [SweetMemoriesConfigController::class, 'index'])->name('index');
                Route::post('', [SweetMemoriesConfigController::class, 'store'])->name('store');
                Route::put('{sweetMemoriesConfig}', [SweetMemoriesConfigController::class, 'update'])->name('update');
            });

            Route::prefix('sweet-memories-images')->name('sweet-memories-images.')->middleware('module.access:memories')->group(function () {
                Route::get('', [SweetMemoriesImageController::class, 'index'])->name('index');
                Route::post('', [SweetMemoriesImageController::class, 'store'])->name('store');
                Route::put('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'update'])->name('update');
                Route::delete('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'destroy'])->name('destroy');
            });

            // ========================================
            // COMMENTS CONFIGURATION
            // ========================================
            Route::prefix('comments-config')->name('comments-config.')->middleware('module.access:comments')->group(function () {
                Route::get('', [EventConfigCommentsController::class, 'index'])->name('index');
                Route::post('', [EventConfigCommentsController::class, 'store'])->name('store');
                Route::put('{commentConfig}', [EventConfigCommentsController::class, 'update'])->name('update');
            });

            Route::scopeBindings()->prefix('comments')->name('comments.')->middleware('module.access:comments')->group(function() {
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
    // STANDALONE FEATURE ROUTES (Outside event context)
    // ========================================
    Route::prefix('sweet-memories-images')->name('sweet-memories-images.')->group(function () {
        Route::patch('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'updateName'])
            ->middleware('module.access:memories')->name('update-name');
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

    // Ends a support (impersonation) session; only works with an impersonation token.
    Route::post('impersonation/end', \App\Http\Controllers\AppControllers\Impersonation\ImpersonationEndController::class)->name('impersonation.end');
});
