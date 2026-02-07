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
            $table->string('extra_phone')
                ->nullable()
                ->default(null)
                ->after('phone_confirmed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('main_guests', function (Blueprint $table) {
            $table->dropColumn('extra_phone');
        });
    }
};
