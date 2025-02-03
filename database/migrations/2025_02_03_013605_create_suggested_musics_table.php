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
        Schema::create('suggested_music', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('name');
            $table->enum('platform', ['youtube', 'spotify'])
                ->default('youtube');
            $table->string('platform_url');
            $table->unsignedBigInteger('suggested_by');
            
            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->onDelete('cascade');
            
            $table->foreign('suggested_by')
                ->references('id')
                ->on('main_guests')
                ->onDelete('cascade');
            $table->timestamps();
        });
        
        Schema::create('suggested_music_votes', function (Blueprint $table) {
           $table->id();
           $table->unsignedBigInteger('suggested_music_id');
           $table->unsignedBigInteger('main_guest_id');
           $table->enum('vote_type', ['up', 'down'])
               ->default('up');
           
           $table->foreign('suggested_music_id')
               ->references('id')
               ->on('suggested_music')
                ->onDelete('cascade');
           $table->foreign('main_guest_id')
               ->references('id')
               ->on('main_guests')
               ->onDelete('cascade');
           $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_music');
        Schema::dropIfExists('suggested_music_votes');
    }
};
