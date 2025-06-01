<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Make phone_number nullable in main_guests
        Schema::table('main_guests', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->change();
            $table->string('access_code', 10)->change();
            $table->enum('companion_type', ['named', 'no_named', 'no_companion'])
                ->default('no_companion')
                ->after('code_used_times');
            $table->integer('companion_qty')->default(0)
                ->after('companion_type');
        });

        // Add email and phone_number as nullable in party_members
        Schema::table('party_members', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert changes for main_guests
        Schema::table('main_guests', function (Blueprint $table) {
            $table->string('phone_number')->nullable(false)->change();
            $table->dropcolumn('companion_type');
            $table->dropcolumn('companion_type');
        });

        // Revert changes for party_members
        Schema::table('party_members', function (Blueprint $table) {
            $table->dropColumn('email');
            $table->dropColumn('phone_number');
        });
    }
};
