<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'organizer_id')) {
                $table->dropColumn('organizer_id');
            }
        });
    }
    
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('organizer_id')->nullable();
            // Opcional: si deseas restaurar como FK
            // $table->foreign('organizer_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
