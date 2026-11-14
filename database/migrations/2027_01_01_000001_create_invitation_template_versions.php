<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('invitation_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft | published | archived
            $table->json('sections');
            $table->json('theme');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version']);
            $table->index(['template_id', 'status']);
        });

        // At most one published and one draft version per template, enforced by the database too.
        DB::statement("CREATE UNIQUE INDEX invitation_template_versions_one_published ON invitation_template_versions (template_id) WHERE status = 'published'");
        DB::statement("CREATE UNIQUE INDEX invitation_template_versions_one_draft ON invitation_template_versions (template_id) WHERE status = 'draft'");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_template_versions');
    }
};
