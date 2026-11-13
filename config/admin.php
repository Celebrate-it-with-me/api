<?php

return [
    // How long the staff audit log is kept before `admin:audit-prune` deletes it.
    'audit_retention_years' => (int) env('ADMIN_AUDIT_RETENTION_YEARS', 2),
];
