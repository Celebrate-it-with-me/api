<?php

use App\Http\Controllers\AppControllers\BackgroundMusicController;
use App\Http\Controllers\AppControllers\Collaborators\InviteCollaboratorController;
use App\Http\Controllers\AppControllers\CompanionController;
use App\Http\Controllers\AppControllers\EventActivity\EventActivityController;
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
use App\Http\Controllers\AppControllers\RsvpController;
use App\Http\Controllers\AppControllers\SaveTheDateController;
use App\Http\Controllers\AppControllers\SuggestedMusicConfigController;
use App\Http\Controllers\AppControllers\SuggestedMusicController;
use App\Http\Controllers\AppControllers\SweetMemoriesConfigController;
use App\Http\Controllers\AppControllers\SweetMemoriesImageController;
use App\Http\Controllers\AppControllers\TemplateController;
use App\Http\Controllers\AppControllers\UserPreferenceController;
use App\Http\Controllers\AppControllers\UserSettingsController;
use App\Http\Controllers\AppControllers\UserTwoFAController;
use App\Http\Controllers\AuthenticationController;

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

// Template Routes
Route::prefix('template')->name('template.')->group(function () {
    Route::prefix('event/{event}')->name('event.')->group(function () {
        Route::get('guest/{guestCode}', [TemplateController::class, 'getEventData'])
            ->name('guest');
        Route::get('guest/{guestCode}/data', [TemplateController::class, 'getGuestData'])
            ->name('guest.data');
        Route::post('save-rsvp', [RsvpController::class, 'saveRsvp'])
            ->name('save-rsvp');
    });
});

// Public Event Routes
Route::prefix('event/{event}')->name('event.')->group(function () {
    Route::get('comments', [EventCommentsController::class, 'index'])
        ->name('comments.index');
    Route::post('comments', [EventCommentsController::class, 'store'])
        ->name('comments.store');

    Route::prefix('suggest-music')->name('suggest-music.')->group(function () {
        Route::post('', [SuggestedMusicController::class, 'store'])->name('store');
        Route::get('', [SuggestedMusicController::class, 'index'])->name('index');
    });
});

// Collaborators Routes
Route::get('event/{event}/collaborators/invite/{token}', [InviteCollaboratorController::class, 'checkToken'])
    ->name('collaborators.checkToken');
Route::post('event/{event}/collaborators/invite/{token}/decline', [InviteCollaboratorController::class, 'declineInvite'])
    ->name('collaborators.declineInvite');
Route::get('collaborators/check-tokens', [InviteCollaboratorController::class, 'eventTokens'])
    ->name('collaborators.eventTokens');


// Protected Routes
Route::middleware(['auth:sanctum', 'refresh.token'])->group(function () {

    // User Routes
    Route::prefix('user')->name('user.')->group(function () {
        Route::get('hydrate/{user}', [HydrateController::class, 'hydrate'])
            ->name('hydrate');
        Route::post('update-profile', [UserSettingsController::class, 'updateProfile'])
            ->name('updateProfile');
        Route::get('preferences', [UserPreferenceController::class, 'showPreferences'])
            ->name('preferences');
        Route::post('preferences', [UserPreferenceController::class, 'updatePreferences'])
            ->name('updatePreferences');
        Route::post('update-password', [UserSettingsController::class, 'updatePassword'])
            ->name('updatePassword');
        Route::get('', [UserSettingsController::class, 'getUser'])
            ->name('show');

        // 2FA Routes
        Route::prefix('2fa')->name('2fa.')->group(function () {
            Route::get('setup', [UserTwoFAController::class, 'setup'])->name('setup');
            Route::post('enable', [UserTwoFAController::class, 'enable'])->name('enable');
            Route::post('disable', [UserTwoFAController::class, 'disable'])->name('disable');
            Route::post('verify', [UserTwoFAController::class, 'verify'])->name('verify');
            Route::get('status', [UserTwoFAController::class, 'status'])->name('status');
            Route::get('recovery-codes', [UserTwoFAController::class, 'recoveryCodes'])->name('recovery-codes');
        });
    });

    // Events Routes
    Route::prefix('events')->name('events.')->group(function () {
        Route::get('', [EventsController::class, 'index'])->name('index');
        Route::get('load-events-plans-and-types', [EventsController::class, 'loanEventsPlansAndType'])
            ->name('loanEventsPlansAndType');
    });

    // Event Routes
    Route::prefix('event')->name('event.')->group(function () {
        // Event CRUD
        Route::post('', [EventsController::class, 'store'])->name('store');
        Route::get('filters', [EventsController::class, 'filterEvents'])->name('filters');

        Route::prefix('{event}')->group(function () {
            Route::get('dashboard-logs', [EventActivityController::class, 'dashboardLogs'])
                ->name('dashboard-logs');
            Route::get('permissions', [EventPermissionsController::class, 'index'])
                ->name('permissions');
            Route::get('suggestions', [EventsController::class, 'suggestions'])
                ->name('suggestions');
            Route::delete('', [EventsController::class, 'destroy'])->name('destroy');
            Route::put('', [EventsController::class, 'update'])->name('update');

            // Save The Date
            Route::prefix('save-the-date')->name('save-the-date.')->group(function () {
                Route::get('', [SaveTheDateController::class, 'index'])->name('index');
                Route::post('', [SaveTheDateController::class, 'store'])->name('store');
            });

            // RSVP
            Route::prefix('rsvp')->name('rsvp.')->group(function () {
                Route::get('', [RsvpController::class, 'index'])->name('index');
                Route::get('summary', [RsvpController::class, 'summary'])->name('summary');

                Route::prefix('guests')->name('guests.')->group(function () {
                    Route::get('', [RsvpController::class, 'getRsvpUsersList'])->name('list');
                    Route::get('totals', [RsvpController::class, 'getRsvpUsersTotals'])->name('totals');
                    Route::get('download', [ExportController::class, 'handleExportRequest'])->name('export');
                    Route::post('{guest}/revert-confirmation', [RsvpController::class, 'revertConfirmation'])
                        ->name('revert-confirmation');
                });
            });

            // Guests
            Route::prefix('guests')->name('guests.')->group(function () {
                Route::get('', [GuestController::class, 'index'])->name('index');
                Route::post('', [GuestController::class, 'store'])->name('store');
                Route::delete('{guest}', [GuestController::class, 'destroy'])->name('destroy');
                Route::get('{guest}', [GuestController::class, 'show'])->name('show');
            });

            // Menus
            Route::prefix('menus')->name('menus.')->group(function () {
                Route::get('', [MenuController::class, 'index'])->name('index');
                Route::post('', [MenuController::class, 'store'])->name('store');
                Route::get('guests', [MenuController::class, 'getGuestsMenu'])->name('guests');
                Route::get('guests/download', [ExportController::class, 'exportGuestMenuSelections'])->name('guests.export');

                Route::prefix('{menu}')->group(function () {
                    Route::put('', [MenuController::class, 'update'])->name('update');
                    Route::delete('', [MenuController::class, 'destroy'])->name('destroy');
                    Route::get('', [MenuController::class, 'show'])->name('show');

                    Route::prefix('menu-item')->name('item.')->group(function () {
                        Route::post('', [MenuItemController::class, 'store'])->name('store');
                        Route::delete('{menuItem}', [MenuItemController::class, 'destroy'])->name('destroy');
                    });
                });
            });

            // Background Music
            Route::prefix('background-music')->name('background-music.')->group(function () {
                Route::get('', [BackgroundMusicController::class, 'index'])->name('index');
                Route::post('', [BackgroundMusicController::class, 'store'])->name('store');
            });

            // Comments Config
            Route::prefix('comments-config')->name('comments-config.')->group(function () {
                Route::get('', [EventConfigCommentsController::class, 'index'])->name('index');
                Route::post('', [EventConfigCommentsController::class, 'store'])->name('store');
                Route::put('{commentConfig}', [EventConfigCommentsController::class, 'update'])->name('update');
            });

            // Suggest Music Config
            Route::prefix('suggest-music-config')->name('suggest-music-config.')->group(function () {
                Route::get('', [SuggestedMusicConfigController::class, 'index'])->name('index');
                Route::post('', [SuggestedMusicConfigController::class, 'store'])->name('store');
            });

            // Sweet Memories Config
            Route::prefix('sweet-memories-config')->name('sweet-memories-config.')->group(function () {
                Route::get('', [SweetMemoriesConfigController::class, 'index'])->name('index');
                Route::post('', [SweetMemoriesConfigController::class, 'store'])->name('store');
                Route::put('{sweetMemoriesConfig}', [SweetMemoriesConfigController::class, 'update'])->name('update');
            });

            // Sweet Memories Images
            Route::prefix('sweet-memories-images')->name('sweet-memories-images.')->group(function () {
                Route::get('', [SweetMemoriesImageController::class, 'index'])->name('index');
                Route::post('', [SweetMemoriesImageController::class, 'store'])->name('store');
                Route::put('', [SweetMemoriesImageController::class, 'update'])->name('update');
                Route::delete('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'destroy'])->name('destroy');
            });

            // Locations
            Route::prefix('locations')->name('locations.')->group(function () {
                Route::get('', [EventLocationController::class, 'index'])->name('index');
                Route::post('', [EventLocationController::class, 'store'])->name('store');
                Route::delete('{location}', [EventLocationController::class, 'destroy'])->name('destroy');
                Route::get('{location}', [EventLocationController::class, 'show'])->name('show');

                Route::get('{placeId}/images', [EventLocationController::class, 'getLocationImages'])->name('images.show');
                Route::post('{location}/images', [EventLocationController::class, 'storeImages'])->name('images.store');
            });
            
            Route::prefix('collaborators')->name('collaborators.')->group(function () {
                Route::post('', [InviteCollaboratorController::class, 'invite'])->name('store');
                Route::post('invite/{id}/accept', [InviteCollaboratorController::class, 'accept'])
                    ->name('accept');
                Route::delete('{user}', [InviteCollaboratorController::class, 'destroy'])->name('destroy');
            });
        });
        

        // Special event routes
        Route::patch('active-event', [EventsController::class, 'activeEvent'])->name('active-event');
    });

    // Guest Routes
    Route::prefix('guest')->name('guest.')->group(function () {
        Route::patch('{guest}', [GuestController::class, 'updateCompanion'])->name('updateCompanion');
        Route::post('{guest}/companion', [CompanionController::class, 'store'])->name('storeCompanion');
    });

    // Companion Routes
    Route::prefix('companion')->name('companion.')->group(function () {
        Route::put('{companion}', [CompanionController::class, 'update'])->name('update');
        Route::delete('{guestCompanion}', [CompanionController::class, 'destroy'])->name('destroy');
    });

    // Suggest Music Routes
    Route::prefix('suggest-music')->name('suggest-music.')->group(function () {
        Route::delete('{suggestedMusic}', [SuggestedMusicController::class, 'destroy'])->name('destroy');
        Route::post('{suggestedMusic}/vote', [SuggestedMusicController::class, 'storeOrUpdate'])->name('vote');
    });

    // Suggest Music Config Routes
    Route::prefix('suggest-music-config')->name('suggest-music-config.')->group(function () {
        Route::put('{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'update'])->name('update');
        Route::delete('{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'destroy'])->name('destroy');
    });

    // Background Music Routes
    Route::prefix('background-music')->name('background-music.')->group(function () {
        Route::post('{backgroundMusic}', [BackgroundMusicController::class, 'update'])->name('update');
    });

    // Save The Date Routes
    Route::prefix('save-the-date')->name('save-the-date.')->group(function () {
        Route::put('{saveTheDate}', [SaveTheDateController::class, 'update'])->name('update');
    });

    // Sweet Memories Images Routes
    Route::prefix('sweet-memories-images')->name('sweet-memories-images.')->group(function () {
        Route::patch('{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'updateName'])->name('update-name');
    });

    // Exports Routes
    Route::prefix('exports')->name('exports.')->group(function () {
        Route::get('download', [ExportController::class, 'exportsDownload'])->name('download');
    });

    // Logout Route
    Route::post('logout', [AuthenticationController::class, 'appLogout'])->name('logout');
});
