<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->foreignId('support_request_id')->nullable()->constrained('support_requests')->nullOnDelete();
            $table->boolean('notify')->default(true);
            // Plain id, no foreign key: the token row is deleted when the session ends and the session row stays.
            $table->unsignedBigInteger('token_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 10)->nullable(); // staff | expired
            $table->timestamps();

            $table->index('staff_id');
            $table->index('user_id');
            $table->index('token_id');
            $table->index(['ended_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
