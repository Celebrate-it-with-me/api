<?php

use App\Http\Controllers\AppControllers\BackgroundMusicController;
use App\Http\Controllers\AppControllers\CompanionController;
use App\Http\Controllers\AppControllers\EventCommentsController;
use App\Http\Controllers\AppControllers\EventConfigCommentsController;
use App\Http\Controllers\AppControllers\EventLocationController;
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
    


Route::get('template/event/{event}/guest/{guestCode}', [TemplateController::class, 'getEventData'])
    ->name('template.event.guest');

Route::get('template/event/{event}/guest/{guestCode}/data', [TemplateController::class, 'getGuestData'])
    ->name('template.event.guest.data');

Route::post('template/event/{event}/save-rsvp', [RsvpController::class, 'saveRsvp'])
    ->name('template.event.save-rsvp');

Route::get('event/{event}/comments', [EventCommentsController::class, 'index'])
    ->name('index.EventComments');

Route::post('event/{event}/comments', [EventCommentsController::class, 'store'])
    ->name('store.EventComments')
;
Route::post('event/{event}/suggest-music', [SuggestedMusicController::class, 'store']);
Route::get('event/{event}/suggest-music', [SuggestedMusicController::class, 'index']);


Route::middleware(['auth:sanctum', 'refresh.token'])->group(function () {
    Route::get('user/hydrate/{user}', [HydrateController::class, 'hydrate'])
        ->name('user.hydrate');
    
    
    Route::post('event', [EventsController::class, 'store']);
    Route::patch('event/active-event', [EventsController::class, 'activeEvent'])
        ->name('event.active-event');
    Route::get('event/{event}/suggestions', [EventsController::class, 'suggestions'])
        ->name('event.suggestions');
    
    Route::get('events', [EventsController::class, 'index']);
    
    Route::get('events/load-events-plans-and-types', [EventsController::class, 'loanEventsPlansAndType'])
        ->name('events.loanEventsPlansAndType');
    Route::get('event/{event}/rsvp/summary', [RsvpController::class, 'summary'])
        ->name('rsvp.summary');
    
    
    Route::get('event/filters', [EventsController::class, 'filterEvents']);
    Route::delete('event/{event}', [EventsController::class, 'destroy']);
    Route::put('event/{event}', [EventsController::class, 'update']);
    
    Route::get('event/{event}/save-the-date', [SaveTheDateController::class, 'index']);
    Route::post('event/{event}/save-the-date', [SaveTheDateController::class, 'store']);
    Route::put('save-the-date/{saveTheDate}', [SaveTheDateController::class, 'update']);
    
    
    Route::get('event/{event}/rsvp', [RsvpController::class, 'index']);
    Route::get('event/{event}/rsvp/guests', [RsvpController::class, 'getRsvpUsersList'])
        ->name('rsvp.guests');
    Route::get('event/{event}/rsvp/guests/totals', [RsvpController::class, 'getRsvpUsersTotals'])
        ->name('rsvp.guests.totals');
    Route::post('event/{event}/rsvp/guests/{guest}/revert-confirmation', [RsvpController::class, 'revertConfirmation'])
        ->name('rsvp.revertConfirmation');
    
    
    Route::get('event/{event}/rsvp/guests/download', [ExportController::class, 'handleExportRequest'])
        ->name('rsvp.request.export');
    
    Route::get('exports/download', [ExportController::class, 'exportsDownload'])
        ->name('exports.download');
    
    Route::get('event/{event}/guests', [GuestController::class, 'index'])
        ->name('index.guests');
    Route::post('event/{event}/guests', [GuestController::class, 'store'])
        ->name('guests.store');
    Route::delete('event/{event}/guests/{guest}', [GuestController::class, 'destroy'])
        ->name('guests.destroy');
    Route::get('event/{event}/guests/{guest}', [GuestController::class, 'show'])
        ->name('guests.show');
    
    Route::patch('guest/{guest}', [GuestController::class, 'updateCompanion'])
        ->name('guest.updateCompanion');
    
    Route::post('guest/{guest}/companion', [CompanionController::class, 'store'])
        ->name('guest.storeCompanion');
    
    Route::put('companion/{companion}', [CompanionController::class, 'update'])
        ->name('companion.update');
    
    Route::delete('companion/{guestCompanion}', [CompanionController::class, 'destroy'])
        ->name('companion.destroy');
    
    Route::delete('suggest-music/{suggestedMusic}', [SuggestedMusicController::class, 'destroy']);
    Route::post('suggest-music/{suggestedMusic}/vote', [SuggestedMusicController::class, 'storeOrUpdate']);
    
    Route::get('event/{event}/suggest-music-config', [SuggestedMusicConfigController::class, 'index']);
    Route::post('event/{event}/suggest-music-config', [SuggestedMusicConfigController::class, 'store']);
    Route::put('suggest-music-config/{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'update']);
    Route::delete('suggest-music-config/{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'destroy']);
    
    Route::get('event/{event}/menus', [MenuController::class, 'index'])
        ->name('index.menu');
    Route::get('event/{event}/menus/guests', [MenuController::class, 'getGuestsMenu'])
        ->name('index.menu.guests');
    Route::get('/event/{event}/menus/guests/download', [ExportController::class, 'exportGuestMenuSelections'])
        ->name('export.guest.menu-export');
    Route::post('event/{event}/menus', [MenuController::class, 'store'])
        ->name('store.menu');
    Route::put('event/{event}/menus/{menu}', [MenuController::class, 'update'])
        ->name('update.menu');
    Route::delete('event/{event}/menus/{menu}', [MenuController::class, 'destroy'])
        ->name('destroy.menu');
    Route::get('event/{event}/menus/{menu}', [MenuController::class, 'show'])
        ->name('show.menu');
    
    Route::post('event/{event}/menus/{menu}/menu-item', [MenuItemController::class, 'store'])
        ->name('store.menuItem');
    Route::delete('event/{event}/menu/{menu}/menu-item/{menuItem}', [MenuItemController::class, 'destroy'])
        ->name('destroy.menuItem');
    
    
    Route::get('event/{event}/background-music', [BackgroundMusicController::class, 'index'])
        ->name('index.backgroundMusic');
    Route::post('event/{event}/background-music', [BackgroundMusicController::class, 'store'])
        ->name('store.backgroundMusic');
    Route::post('background-music/{backgroundMusic}', [BackgroundMusicController::class, 'update'])
        ->name('update.backgroundMusic');
    
    Route::get('event/{event}/comments-config', [EventConfigCommentsController::class, 'index'])
        ->name('index.configComments');
    Route::post('event/{event}/comments-config', [EventConfigCommentsController::class, 'store'])
        ->name('store.configComments');
    Route::put('event/{event}/comments-config/{commentConfig}', [EventConfigCommentsController::class, 'update'])
        ->name('update.configComments');
    
    Route::get('event/{event}/sweet-memories-config', [SweetMemoriesConfigController::class, 'index'])
        ->name('index.sweetMemoriesConfig');
    Route::post('event/{event}/sweet-memories-config', [SweetMemoriesConfigController::class, 'store'])
        ->name('store.sweetMemoriesConfig');
    Route::put('event/{event}/sweet-memories-config/{sweetMemoriesConfig}', [SweetMemoriesConfigController::class, 'update'])
        ->name('update.sweetMemoriesConfig');
    
    Route::get('event/{event}/locations', [EventLocationController::class, 'index'])
        ->name('index.eventLocations');
    Route::post('event/{event}/locations', [EventLocationController::class, 'store'])
        ->name('store.eventLocations');
    Route::delete('event/{event}/locations/{location}', [EventLocationController::class, 'destroy'])
        ->name('destroy.eventLocations');
    Route::get('event/{event}/locations/{location}', [EventLocationController::class, 'show'])
        ->name('show.eventLocations');
    
    
    Route::post('event/{event}/sweet-memories-images', [SweetMemoriesImageController::class, 'store'])
        ->name('store.sweetMemoriesImages');
    Route::get('event/{event}/sweet-memories-images', [SweetMemoriesImageController::class, 'index'])
        ->name('index.sweetMemoriesImages');
    Route::put('event/{event}/sweet-memories-images', [SweetMemoriesImageController::class, 'update'])
        ->name('update.sweetMemoriesImages');
    Route::delete('event/{event}/sweet-memories-images/{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'destroy'])
        ->name('destroy.sweetMemoriesImages');
    
    Route::patch('sweet-memories-images/{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'updateName'])
        ->name('update.sweetMemoriesImages.name');
    
    Route::post('user/update-profile', [UserSettingsController::class, 'updateProfile'])
        ->name('user.updateProfile');
    Route::get('user/preferences', [UserPreferenceController::class, 'showPreferences'])
        ->name('user.preferences');
    Route::post('user/preferences', [UserPreferenceController::class, 'updatePreferences'])
        ->name('user.updatePreferences');
    Route::post('user/update-password', [UserSettingsController::class, 'updatePassword'])
        ->name('user.updatePassword');
    
    Route::prefix('user/2fa')
        ->name('user.2fa.')
        ->group(function () {
            Route::get('setup', [UserTwoFAController::class, 'setup'])->name('setup');
            Route::post('enable', [UserTwoFAController::class, 'enable'])->name('enable');
            Route::post('disable', [UserTwoFAController::class, 'disable'])->name('disable');
            Route::post('verify', [UserTwoFAController::class, 'verify'])->name('verify');
            Route::get('status', [UserTwoFAController::class, 'status'])->name('status');
            Route::get('recovery-codes', [UserTwoFAController::class, 'recoveryCodes'])->name('recovery-codes');
        });
    
    Route::get('user', [UserSettingsController::class, 'getUser'])
        ->name('user.show');
    Route::post('logout', [AuthenticationController::class, 'appLogout']);
});

