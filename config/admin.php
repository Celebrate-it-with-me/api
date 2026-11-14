<?php

return [
    // How long the staff audit log is kept before `admin:audit-prune` deletes it.
    'audit_retention_years' => (int) env('ADMIN_AUDIT_RETENTION_YEARS', 2),

    // Two-factor authentication (TOTP) of the staff is optional per person. Setting ADMIN_REQUIRE_2FA_SUPERADMIN=true
    // forces it for every `superadmin`: until they enrol, the only things they can open are their own account pages
    // (`admin.account.*`) and `userInfo`; the rest answers 403 with code `two_factor_required`. Off by default.
    'require_two_factor_superadmin' => (bool) env('ADMIN_REQUIRE_2FA_SUPERADMIN', false),

    // How long the link to confirm a new sign-in email lives, in minutes.
    'email_change_minutes' => (int) env('ADMIN_EMAIL_CHANGE_MINUTES', 60),
];
