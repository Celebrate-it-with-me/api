<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Billing contact email on the user's billing data, and the "credit note already mailed" mark (idempotent sending). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->string('billing_email')->nullable()->after('name');
        });
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', fn (Blueprint $table) => $table->dropColumn('emailed_at'));
        Schema::table('billing_profiles', fn (Blueprint $table) => $table->dropColumn('billing_email'));
    }
};
