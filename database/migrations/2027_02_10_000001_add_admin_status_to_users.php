<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts about a staff member that the team list shows: when she last signed in to the panel, and whether her
 * access is suspended (she keeps the role and the account, but the panel refuses her).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('admin_last_login_at')->nullable();
            $table->timestamp('admin_suspended_at')->nullable();
        });

        // The audit log already knows every past sign-in: start from the last one.
        if (Schema::hasTable('admin_audit_log')) {
            DB::statement("UPDATE users SET admin_last_login_at = (SELECT MAX(created_at) FROM admin_audit_log WHERE admin_audit_log.actor_id = users.id AND admin_audit_log.action = 'auth.login')");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['admin_last_login_at', 'admin_suspended_at']);
        });
    }
};
