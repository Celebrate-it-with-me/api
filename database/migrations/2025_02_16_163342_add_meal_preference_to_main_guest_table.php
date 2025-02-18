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
            $table->unsignedBigInteger('meal_preference')->default(0)->after('companion_qty');
        });
        
        Schema::table('guest_companions', function (Blueprint $table) {
           $table->unsignedBigInteger('meal_preference')->default(0)->after('confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('main_guests', function (Blueprint $table) {
            $table->dropColumn('meal_preference');
        });
        
        Schema::table('guest_companions', function (Blueprint $table) {
            $table->dropColumn('meal_preference');
        });
    }
};
