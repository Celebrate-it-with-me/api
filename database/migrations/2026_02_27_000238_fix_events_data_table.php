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
        /**
         * 1) DATA FIX: visibility
         */
        DB::table('events')
            ->where('visibility', 'restricted')
            ->update(['visibility' => 'private']);
        
        /**
         * 2) SCHEMA: make visibility portable (string)
         */
        Schema::table('events', function (Blueprint $table) {
            $table->string('visibility', 20)->default('private')->change();
        });
        
        /**
         * 3) SCHEMA: add active_event_theme_id + published_at
         */
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('active_event_theme_id')
                ->nullable()
                ->after('event_type_id')
                ->constrained('event_themes')
                ->nullOnDelete();
            
            $table->timestamp('published_at')
                ->nullable()
                ->after('status');
            
            $table->index(['status', 'visibility'], 'events_status_visibility_idx');
            $table->index('custom_url_slug', 'events_custom_url_slug_idx');
        });
        
        /**
         * 4) DATA FIX: published_at for already published events
         */
        DB::table('events')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update([
                'published_at' => DB::raw('COALESCE(updated_at, created_at)')
            ]);
        
        /**
         * 5) DATA FIX: ensure custom_url_slug is unique BEFORE adding unique constraint
         *    Strategy:
         *      - normalize empty slugs -> slugified event_name + id
         *      - for duplicates -> append "-{id}"
         */
        $events = DB::table('events')
            ->select('id', 'event_name', 'custom_url_slug')
            ->orderBy('id')
            ->get();
        
        $seen = [];
        
        foreach ($events as $e) {
            $slug = trim((string) $e->custom_url_slug);
            
            if ($slug === '') {
                $base = Str::slug((string) $e->event_name) ?: 'event';
                $slug = $base . '-' . $e->id;
            }
            
            // If slug already used, make it unique by suffixing id
            if (isset($seen[$slug])) {
                $slug = $slug . '-' . $e->id;
            }
            
            $seen[$slug] = true;
            
            DB::table('events')
                ->where('id', $e->id)
                ->update(['custom_url_slug' => $slug]);
        }
        
        /**
         * 6) SCHEMA: enforce unique slug (now safe)
         */
        Schema::table('events', function (Blueprint $table) {
            $table->unique('custom_url_slug', 'events_custom_url_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_custom_url_slug_unique');
            
            $table->dropIndex('events_status_visibility_idx');
            $table->dropIndex('events_custom_url_slug_idx');
            
            $table->dropConstrainedForeignId('active_event_theme_id');
            $table->dropColumn('published_at');
        });
    }
};
