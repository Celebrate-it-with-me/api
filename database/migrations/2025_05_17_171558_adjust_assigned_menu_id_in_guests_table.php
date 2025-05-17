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
            Schema::table('guests', function (Blueprint $table) {
                $table->dropForeign(['assigned_menu_id']);
                
                $table->foreign('assigned_menu_id')
                    ->references('id')
                    ->on('menus')
                    ->nullOnDelete();
            });
        }
        
        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('guests', function (Blueprint $table) {
                $table->dropForeign(['assigned_menu_id']);
                
                $table->foreign('assigned_menu_id')
                    ->references('id')
                    ->on('menu_items')
                    ->nullOnDelete();
            });
        }
    };
