<?php

/*
 * Admin API: Event content (Contenido tab) and the event log (Registro tab). Included from routes/api/admin/events.php (inside the staff group, prefix api/v1/admin).
 * Paths start with `events/{event}/...`. Each route declares its permission and every write is audited.
 */

use Illuminate\Support\Facades\Route;
