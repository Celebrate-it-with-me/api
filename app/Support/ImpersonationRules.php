<?php

namespace App\Support;

use Illuminate\Routing\Route;

/**
 * What a user token issued by impersonation may do in the app (docs/plans/admin-brief.md, A3).
 *
 * Every route of the authenticated app group has to be in exactly one of the two lists, as "METHOD uri": BLOCKED
 * answers 403 `impersonation_blocked`, ALLOWED passes. A route that is in neither is blocked too (fail closed) and
 * `ImpersonationBlocksTest` fails until somebody classifies it on purpose.
 */
final class ImpersonationRules
{
    /** Passwords, 2FA, sessions, purchases and payments, exports of personal data, deleting, access to the event. */
    public const BLOCKED = [
        'DELETE api/v1/app/event/{event}',
        'DELETE api/v1/app/event/{event}/collaborators/{user}',
        'GET api/v1/app/event/{event}/budget/export',
        'GET api/v1/app/event/{event}/menus/guests/download',
        'GET api/v1/app/event/{event}/purchase/quote',
        'GET api/v1/app/event/{event}/purchase/status',
        'GET api/v1/app/event/{event}/rsvp/guests/download',
        'GET api/v1/app/event/{event}/suggest-music/export',
        'GET api/v1/app/exports/download',
        // Billing documents of the owner: support sees them from the admin, not by impersonating.
        'GET api/v1/app/account/purchases',
        'GET api/v1/app/account/invoices/{invoice}/pdf',
        'GET api/v1/app/account/credit-notes/{creditNote}/pdf',
        'GET api/v1/app/user/2fa/recovery-codes',
        'GET api/v1/app/user/2fa/setup',
        'GET api/v1/app/user/2fa/status',
        'POST api/v1/app/event/{event}/collaborators',
        'POST api/v1/app/event/{event}/collaborators/invite/{id}/accept',
        'POST api/v1/app/event/{event}/purchase/checkout',
        'POST api/v1/app/logout',
        'POST api/v1/app/support',
        'POST api/v1/app/user/2fa/disable',
        'POST api/v1/app/user/2fa/enable',
        'POST api/v1/app/user/2fa/verify',
        'POST api/v1/app/user/update-password',
    ];

    /** Everything else the organizer does in her own account, so support can reproduce and fix what she sees. */
    public const ALLOWED = [
        'DELETE api/v1/app/event/{event}/budget/categories/{category}',
        'DELETE api/v1/app/event/{event}/budget/items/{item}',
        'DELETE api/v1/app/event/{event}/budget/items/{item}/payments/{payment}',
        'DELETE api/v1/app/event/{event}/comments/{comment}',
        'DELETE api/v1/app/event/{event}/dress-code',
        'DELETE api/v1/app/event/{event}/location/{location}',
        'DELETE api/v1/app/event/{event}/location/{location}/images/{image}',
        'DELETE api/v1/app/event/{event}/menus/{menu}',
        'DELETE api/v1/app/event/{event}/menus/{menu}/menu-item/{menuItem}',
        'DELETE api/v1/app/event/{event}/padrinos/{padrino}',
        'DELETE api/v1/app/event/{event}/roster/{guest}',
        'DELETE api/v1/app/event/{event}/save-the-date',
        'DELETE api/v1/app/event/{event}/seating/clear-all',
        'DELETE api/v1/app/event/{event}/suggest-music/{suggestedMusic}',
        'DELETE api/v1/app/event/{event}/sweet-memories-images/{sweetMemoriesImage}',
        'DELETE api/v1/app/event/{event}/tables/{table}',
        'DELETE api/v1/app/event/{event}/tables/{table}/guest/{guest}',
        'DELETE api/v1/app/event/{event}/timeline/{timeline}',
        'GET api/v1/app/auth/ready',
        'GET api/v1/app/event/filters',
        'GET api/v1/app/event/{event}',
        'GET api/v1/app/event/{event}/background-music',
        'GET api/v1/app/event/{event}/budget',
        'GET api/v1/app/event/{event}/budget/items',
        'GET api/v1/app/event/{event}/comments',
        'GET api/v1/app/event/{event}/comments-config',
        'GET api/v1/app/event/{event}/comments/paginated',
        'GET api/v1/app/event/{event}/dashboard-logs',
        'GET api/v1/app/event/{event}/dress-code',
        'GET api/v1/app/event/{event}/location',
        'GET api/v1/app/event/{event}/location/{location}',
        'GET api/v1/app/event/{event}/location/{placeId}/images',
        'GET api/v1/app/event/{event}/menus',
        'GET api/v1/app/event/{event}/menus/guests',
        'GET api/v1/app/event/{event}/menus/{menu}',
        'GET api/v1/app/event/{event}/onboarding',
        'GET api/v1/app/event/{event}/organizer/rsvp/{guest}/status',
        'GET api/v1/app/event/{event}/padrinos',
        'GET api/v1/app/event/{event}/permissions',
        'GET api/v1/app/event/{event}/roster',
        'GET api/v1/app/event/{event}/roster/import/template',
        'GET api/v1/app/event/{event}/rsvp/stats',
        'GET api/v1/app/event/{event}/save-the-date',
        'GET api/v1/app/event/{event}/seating/unassigned',
        'GET api/v1/app/event/{event}/suggest-music',
        'GET api/v1/app/event/{event}/suggest-music-config',
        'GET api/v1/app/event/{event}/suggestions',
        'GET api/v1/app/event/{event}/sweet-memories-config',
        'GET api/v1/app/event/{event}/sweet-memories-images',
        'GET api/v1/app/event/{event}/tables',
        'GET api/v1/app/event/{event}/tables/{table}',
        'GET api/v1/app/event/{event}/timeline',
        'GET api/v1/app/events',
        'GET api/v1/app/events/load-events-plans-and-types',
        'GET api/v1/app/support',
        'GET api/v1/app/user',
        'GET api/v1/app/user/hydrate/{user}',
        'GET api/v1/app/user/preferences',
        'PATCH api/v1/app/event/active-event',
        'PATCH api/v1/app/event/{event}/comments/{comment}/favorite',
        'PATCH api/v1/app/event/{event}/comments/{comment}/pin',
        'PATCH api/v1/app/event/{event}/comments/{comment}/status',
        'PATCH api/v1/app/sweet-memories-images/{sweetMemoriesImage}',
        'POST api/v1/app/event',
        'POST api/v1/app/event/{event}/archive',
        'POST api/v1/app/event/{event}/background-music',
        'POST api/v1/app/event/{event}/budget/categories',
        'POST api/v1/app/event/{event}/budget/items',
        'POST api/v1/app/event/{event}/budget/items/{item}/payments',
        'POST api/v1/app/event/{event}/comments',
        'POST api/v1/app/event/{event}/comments-config',
        'POST api/v1/app/event/{event}/dress-code',
        'POST api/v1/app/event/{event}/guests',
        'POST api/v1/app/event/{event}/location',
        'POST api/v1/app/event/{event}/location/{location}/images',
        'POST api/v1/app/event/{event}/menus',
        'POST api/v1/app/event/{event}/menus/{menu}/menu-item',
        'POST api/v1/app/event/{event}/padrinos',
        'POST api/v1/app/event/{event}/publish',
        'POST api/v1/app/event/{event}/restore',
        'POST api/v1/app/event/{event}/roster/import',
        'POST api/v1/app/event/{event}/roster/import/preview',
        'POST api/v1/app/event/{event}/roster/{guest}/companions',
        'POST api/v1/app/event/{event}/rsvp/guests/{guest}/revert-confirmation',
        'POST api/v1/app/event/{event}/seating/bulk-assign',
        'POST api/v1/app/event/{event}/suggest-music',
        'POST api/v1/app/event/{event}/suggest-music-config',
        'POST api/v1/app/event/{event}/sweet-memories-config',
        'POST api/v1/app/event/{event}/sweet-memories-images',
        'POST api/v1/app/event/{event}/tables',
        'POST api/v1/app/event/{event}/tables/{table}/assign',
        'POST api/v1/app/event/{event}/timeline',
        'POST api/v1/app/event/{event}/timeline/typical',
        'POST api/v1/app/event/{event}/unpublish',
        'POST api/v1/app/impersonation/end',
        'POST api/v1/app/user/preferences',
        'POST api/v1/app/user/update-profile',
        'PUT api/v1/app/event/{event}',
        'PUT api/v1/app/event/{event}/budget',
        'PUT api/v1/app/event/{event}/budget/categories/{category}',
        'PUT api/v1/app/event/{event}/budget/items/{item}',
        'PUT api/v1/app/event/{event}/budget/items/{item}/payments/{payment}',
        'PUT api/v1/app/event/{event}/comments-config/{commentConfig}',
        'PUT api/v1/app/event/{event}/location/{location}',
        'PUT api/v1/app/event/{event}/menus/{menu}',
        'PUT api/v1/app/event/{event}/organizer/rsvp/{guest}/bulk-companions',
        'PUT api/v1/app/event/{event}/organizer/rsvp/{guest}/confirm',
        'PUT api/v1/app/event/{event}/padrinos/{padrino}',
        'PUT api/v1/app/event/{event}/padrinos/{padrino}/commitments',
        'PUT api/v1/app/event/{event}/roster/{guest}',
        'PUT api/v1/app/event/{event}/save-the-date',
        'PUT api/v1/app/event/{event}/sweet-memories-config/{sweetMemoriesConfig}',
        'PUT api/v1/app/event/{event}/sweet-memories-images/{sweetMemoriesImage}',
        'PUT api/v1/app/event/{event}/tables/{table}',
        'PUT api/v1/app/event/{event}/timeline/{timeline}',
    ];

    /** The key of a route in both lists: its first method (never HEAD) and its URI with placeholders. */
    public static function key(Route $route): string
    {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return $method . ' ' . $route->uri();
    }

    public static function isAllowed(Route $route): bool
    {
        $key = self::key($route);

        return ! in_array($key, self::BLOCKED, true) && in_array($key, self::ALLOWED, true);
    }
}
