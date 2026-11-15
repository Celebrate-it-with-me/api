<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth access/refresh tokens are stored encrypted (the `encrypted` cast of SocialAccount). The columns are already
 * `text` on installs created from the original migration; they are widened here for any install where they are not.
 * Existing plain values are encrypted once: a value that already decrypts is left alone, so running this twice (or
 * after the app started writing encrypted values) never double-encrypts and no social session is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('access_token')->nullable()->change();
            $table->text('refresh_token')->nullable()->change();
        });

        DB::table('social_accounts')->orderBy('id')->each(function ($row) {
            $update = [];
            foreach (['access_token', 'refresh_token'] as $column) {
                $value = $row->{$column};
                if ($value === null || $value === '' || $this->isEncrypted($value)) {
                    continue;
                }
                $update[$column] = Crypt::encryptString($value);
            }
            if ($update !== []) {
                DB::table('social_accounts')->where('id', $row->id)->update($update);
            }
        });
    }

    /** Not reversible on purpose: decrypting back would put the tokens in plain text again. */
    public function down(): void {}

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
