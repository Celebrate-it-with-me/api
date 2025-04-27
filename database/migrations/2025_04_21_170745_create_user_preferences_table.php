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
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('language')->default('en');
            $table->string('timezone')->default('America/New_York');
            $table->string('visual_theme')->default('system');
            $table->string('date_format')->default('MM/DD/YYYY');
            
            $table->boolean('notify_by_email')->default(true);
            $table->boolean('notify_by_sms')->default(false);
            $table->boolean('smart_tips')->default(true);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
