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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();
            
            $table->string('name', 50);
            $table->integer('capacity');
            $table->enum('type', ['vip', 'family', 'friends', 'general']);
            $table->integer('priority');
            $table->string('reserved_for')->nullable();
            $table->enum('location', ['front', 'center', 'back', 'side', 'entrance'])->nullable();
            $table->timestamps();
        });
        
        Schema::create('table_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')
                ->constrained('tables')
                ->cascadeOnDelete();
            
            $table->foreignId('guest_id')
                ->constrained('guests')
                ->cascadeOnDelete();
            
            $table->string('seat_number');
            $table->dateTime('assigned_at');
            $table->foreignId('assigned_by')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
        Schema::dropIfExists('table_assignments');
    }
};
