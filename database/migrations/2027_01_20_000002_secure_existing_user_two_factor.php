<?php

use App\Http\Services\AppServices\UserTwoFactorService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: secrets that were stored in plain text are encrypted, and recovery codes stored as a JSON list of
 * plain strings become keyed hashes ([{hash, used_at}]). The old codes keep working (they are hashed, not replaced)
 * but can never be displayed again. Idempotent: rows already converted are left alone.
 *
 * down(): decrypts the secrets back to plain text (so the previous code can read them); the recovery codes cannot be
 * recovered from their hashes, so they are cleared and the user has to generate new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where(fn ($q) => $q->whereNotNull('two_factor_secret')->orWhereNotNull('two_factor_recovery_codes'))
            ->orderBy('id')
            ->each(function ($row) {
                $update = [];

                if ($row->two_factor_secret !== null && $row->two_factor_secret !== '' && ! $this->isEncrypted($row->two_factor_secret)) {
                    $update['two_factor_secret'] = Crypt::encryptString($row->two_factor_secret);
                }

                if ($row->two_factor_recovery_codes !== null && $row->two_factor_recovery_codes !== '') {
                    $converted = $this->hashCodes($row->two_factor_recovery_codes);
                    if ($converted !== null) {
                        $update['two_factor_recovery_codes'] = json_encode($converted);
                    }
                }

                if ($update !== []) {
                    DB::table('users')->where('id', $row->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('two_factor_secret')->orderBy('id')->each(function ($row) {
            if ($this->isEncrypted($row->two_factor_secret)) {
                DB::table('users')->where('id', $row->id)->update(['two_factor_secret' => Crypt::decryptString($row->two_factor_secret)]);
            }
        });
        DB::table('users')->whereNotNull('two_factor_recovery_codes')->update(['two_factor_recovery_codes' => null]);
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /** @return list<array{hash: string, used_at: ?string}>|null null when the value is already in the new format (or unreadable) */
    private function hashCodes(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }
        if ($decoded === []) {
            return null;
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $out[] = ['hash' => UserTwoFactorService::hash($item), 'used_at' => null];
            } elseif (is_array($item) && isset($item['hash'])) {
                return null; // already converted
            }
        }

        return $out === [] ? null : $out;
    }
};
