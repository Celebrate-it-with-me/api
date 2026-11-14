<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a token was issued from, so "Mi cuenta" can list the active sessions of the panel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // 'admin' for tokens of the admin panel; null for the ones issued before this column existed or by the app.
            $table->string('client', 20)->nullable()->after('abilities');
            $table->string('ip_address', 45)->nullable()->after('client');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['client', 'ip_address', 'user_agent']);
        });
    }
};
