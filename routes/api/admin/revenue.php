<?php

/*
 * Admin API: Ingresos (A8), the global view of purchases and the price/codes. Included from routes/api/admin.php inside the
 * staff group. Superadmin only (admin.purchases.manage, admin.refunds, admin.pricing.manage). Every write is audited.
 */

use Illuminate\Support\Facades\Route;
