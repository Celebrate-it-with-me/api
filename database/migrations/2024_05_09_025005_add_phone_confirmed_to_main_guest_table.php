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
        Schema::table('main_guests', function (Blueprint $table) {
            $table->boolean('phone_confirmed')->after('phone_number')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('main_guests', function (Blueprint $table) {
            $table->dropColumn('phone_confirmed');
        });
    }
};
