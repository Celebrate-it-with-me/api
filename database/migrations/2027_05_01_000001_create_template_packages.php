<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installed template packages (docs/plans/template-package-spec.md, 6.1): one immutable row per slug + semver; only
 * `status` and its dates change. The sanitized files live on the `template-packages` disk, everything the engine needs
 * to draw the invitation (AST of the sections, tokens, strings, editable fields) lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32);
            $table->string('semver', 16);
            $table->unsignedSmallInteger('level')->default(1);
            $table->string('visibility', 8)->default('public'); // public | private
            $table->string('tier', 8); // free | premium
            $table->string('status', 12)->default('installed'); // installed | active | deactivated | withdrawn
            $table->unsignedSmallInteger('engine_min');
            $table->json('manifest');
            $table->json('tokens');
            $table->json('editable');
            $table->json('strings')->nullable();
            $table->json('ast'); // {"layout": ast|null, "sections": {type: ast}}
            $table->json('files'); // [{path, size, sha256, mime}]
            $table->json('report'); // the validation report the install was accepted with (warnings included)
            $table->char('content_hash', 64);
            $table->char('zip_sha256', 64);
            $table->string('storage_path')->nullable(); // null for level 2 without files
            $table->unsignedInteger('size_bytes');
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['slug', 'semver']);
            $table->index(['status', 'visibility']);
        });

        Schema::table('invitation_template_versions', function (Blueprint $table) {
            // Versions created by a package install are managed by it (their content is not editable by hand).
            $table->foreignId('package_id')->nullable()->after('template_id')->constrained('template_packages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitation_template_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
        Schema::dropIfExists('template_packages');
    }
};
