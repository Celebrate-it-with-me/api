<?php

namespace App\Models\WhatsApp;

use App\Support\WhatsApp\Phone;
use Illuminate\Database\Eloquent\Model;

/** A phone number (E.164) that asked to stop receiving WhatsApp messages. Survives guests being deleted or recreated. */
class OptOut extends Model
{
    public $timestamps = false;

    protected $table = 'whatsapp_opt_outs';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public static function has(?string $phone): bool
    {
        $e164 = Phone::normalize($phone);

        return $e164 !== null && static::query()->where('phone_e164', $e164)->exists();
    }

    /** @param list<string> $e164s @return array<string, true> the given numbers that opted out, as a set */
    public static function among(array $e164s): array
    {
        if ($e164s === []) {
            return [];
        }

        return static::query()->whereIn('phone_e164', array_values(array_unique($e164s)))->pluck('phone_e164')->flip()->map(fn () => true)->all();
    }

    public static function add(string $e164): void
    {
        static::query()->insertOrIgnore(['phone_e164' => $e164, 'created_at' => now()]);
    }
}
