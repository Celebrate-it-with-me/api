# Changelog

All notable changes to this project are documented here.
Versioning follows SemVer. See docs/RELEASING.md in the workspace repo.

## [0.0.1] - 2026-11-17

Baseline: state of `develop` on 2026-11-17 (about 770 commits). Written by hand as a summary by type,
not generated with git-cliff (not installed in the agent environment); later releases use `git cliff --tag vX.Y.Z --unreleased --prepend CHANGELOG.md`.

### Features

- Organizer app API: events, guests, RSVP, budget, dress code, collaborators with event roles, activity feed, assistant with task detectors.
- Public invitation: template packages (upload, validation, sanitizers, install, activation), template selector and editor, comments, tables, gallery, background music and suggested music sections.
- Payments: Stripe gateway, webhook, reconciliation, refunds, chargeback status, tax on invoices, plan-based limits and premium module gates.
- WhatsApp channel and transactional mail with editable texts in admin.
- Accounts: organizer email verification, safe social login linking, account deletion and purge.
- Admin API: staff team and invitations, two-factor switch, events, users, support, moderation, impersonation, revenue, catalog, templates, announcements, messages, system health and maintenance mode.
- Platform: Spanish/English validation messages, configurable API rate limit, gzip, ETag and no-store cache control, `/up` health check.
- Release versioning: `VERSION` file, `config/version.php` and `GET /api/v1/admin/system/version`.

### Fixes

- Hardening across auth, security, CORS, payments, WhatsApp, collaborators, impersonation and invitations.
- PostgreSQL-safe migrations, seeders, hydration, mail and i18n corrections.
