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
        Schema::table('event_comments', function (Blueprint $table) {
            // Polymorphic author (Guest or User). Keep nullable for safe backfill.
            $table->string('authorable_type')->nullable()->after('created_by_class');
            $table->unsignedBigInteger('authorable_id')->nullable()->after('authorable_type');

            // Moderation status
            $table->string('status')->default('visible')->after('is_approved');

            // UX features
            $table->boolean('is_pinned')->default(false)->after('status');
            $table->boolean('is_favorite')->default(false)->after('is_pinned');

            // Soft deletes for safe removals
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['authorable_type', 'authorable_id'], 'event_comments_authorable_index');
            $table->index(['event_id', 'status'], 'event_comments_event_status_index');
            $table->index(['event_id', 'is_pinned'], 'event_comments_event_pinned_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_comments', function (Blueprint $table) {
            $table->dropIndex('event_comments_authorable_index');
            $table->dropIndex('event_comments_event_status_index');
            $table->dropIndex('event_comments_event_pinned_index');

            $table->dropSoftDeletes();

            $table->dropColumn([
                'authorable_type',
                'authorable_id',
                'status',
                'is_pinned',
                'is_favorite',
            ]);
        });
    }
};
