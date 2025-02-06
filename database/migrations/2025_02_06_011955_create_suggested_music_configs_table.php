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
        Schema::create('suggested_music_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('main_color')->nullable();
            $table->string('secondary_color')->nullable();
            $table->tinyInteger('use_suggested_music')->default(0);
            $table->tinyInteger('use_preview')->default(0);
            $table->tinyInteger('use_vote_system')->default(0);
            $table->integer('search_limit')->default(10);
            
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_music_configs');
    }
};
