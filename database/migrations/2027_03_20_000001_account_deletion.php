<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account deletion (docs/plans/account-deletion-contract.md).
 *
 * - events.archived_at / purge_after: set only when an organizer deletes her account and the event was published. The
 *   event stays readable (public invitation, read only) until `account:purge-archived` removes it. An event archived
 *   by hand has both columns null and keeps the old behaviour (it can be restored and serves no invitation).
 * - users.account_deleted_at: the person deleted her own account (anonymized_at is also set, as for the admin tool).
 * - users.has_password: false while the only way into the account is a social login (its stored password is random
 *   and unknown), so deleting it cannot ask for a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('purge_after')->nullable()->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('account_deleted_at')->nullable();
            $table->boolean('has_password')->default(true);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Accounts born from a social login (the social account was linked right when the user was created) and that
        // never changed their password have no password of their own.
        DB::table('users')
            ->whereNull('password_changed_at')
            ->whereIn('id', DB::table('social_accounts')
                ->join('users as u', 'u.id', '=', 'social_accounts.user_id')
                ->whereRaw("social_accounts.created_at <= u.created_at + interval '1 minute'")
                ->select('social_accounts.user_id'))
            ->update(['has_password' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['account_deleted_at', 'has_password']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['purge_after']);
            $table->dropColumn(['archived_at', 'purge_after']);
        });
    }
};
