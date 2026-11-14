<?php

return [
    // How long the staff audit log is kept before `admin:audit-prune` deletes it.
    'audit_retention_years' => (int) env('ADMIN_AUDIT_RETENTION_YEARS', 2),

    // Two-factor authentication (TOTP) of the staff is optional per person. Setting ADMIN_REQUIRE_2FA_SUPERADMIN=true
    // forces it for every `superadmin`: until they enrol, the only things they can open are their own account pages
    // (`admin.account.*`) and `userInfo`; the rest answers 403 with code `two_factor_required`. Off by default.
    // The panel has its own switch (System > Security, stored in `system_flags`) that requires it from ALL staff. The two
    // rules are ORed: this variable stays as a fallback for superadmins and cannot be turned off from the panel.
    'require_two_factor_superadmin' => (bool) env('ADMIN_REQUIRE_2FA_SUPERADMIN', false),

    // How long the link to confirm a new sign-in email lives, in minutes.
    'email_change_minutes' => (int) env('ADMIN_EMAIL_CHANGE_MINUTES', 60),

    // Lifetime of the admin panel's tokens (client = 'admin'). A token that stays unused for this many hours dies
    // (idle), and none lives longer than this many days since sign-in (absolute). Both answer 401 `session_expired`.
    // Impersonation tokens (their own 45-minute life) and the organizers' app tokens are not governed by these.
    'token_idle_hours' => (int) env('ADMIN_TOKEN_IDLE_HOURS', 12),
    'token_max_days' => (int) env('ADMIN_TOKEN_MAX_DAYS', 7),

    // How long a successful re-authentication (`POST admin/account/reauth`) keeps the very sensitive actions open, in minutes.
    'reauth_minutes' => (int) env('ADMIN_REAUTH_MINUTES', 10),
];
