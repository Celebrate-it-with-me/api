<?php

/*
 * How long tables that only grow are kept (staging readiness, 2026-11-20). `php artisan data:prune` applies these, daily
 * (routes/console.php). Each value is a number of days unless the key says otherwise; the lower bound of every window is 1.
 */
return [
    // Dedupe keys of Meta webhook events. Meta retries for about a week; a month is a wide margin.
    'whatsapp_inbound_events_days' => (int) env('RETENTION_WHATSAPP_INBOUND_EVENTS_DAYS', 30),

    // Delivery statuses that arrived before their message row existed. A status that found no message in two weeks never will.
    'whatsapp_pending_statuses_days' => (int) env('RETENTION_WHATSAPP_PENDING_STATUSES_DAYS', 14),

    // Processed Stripe events (dedupe + outcome trail). Stripe retries for days; kept longer as a financial trail.
    'payment_events_days' => (int) env('RETENTION_PAYMENT_EVENTS_DAYS', 400),

    // Impersonation sessions that already ended: an audit trail, so they follow the staff audit log's retention (admin.audit_retention_years).

    // Failed jobs shown in the admin panel.
    'failed_jobs_days' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
];
