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
        Schema::create('event_collaboration_invites', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('role')->default('viewer');
            
            $table->string('token')->unique();
            $table->enum('status', ['pending', 'accepted', 'expired'])->default('pending');
            
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_collaboration_invites');
    }
};
