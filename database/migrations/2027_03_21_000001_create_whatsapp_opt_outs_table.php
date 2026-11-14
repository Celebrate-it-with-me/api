<?php

use App\Support\WhatsApp\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The WhatsApp opt-out ("STOP") belongs to the phone number, not to a guest row (audit W-02). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164', 20)->unique();
            $table->timestamp('created_at')->nullable();
        });

        // Carry over the opt-outs already stored on guests.
        DB::table('guests')->whereNotNull('whatsapp_opted_out_at')->select(['id', 'phone', 'whatsapp_opted_out_at'])->orderBy('id')
            ->chunkById(500, function ($guests) {
                foreach ($guests as $guest) {
                    $phone = Phone::normalize($guest->phone);
                    if ($phone) {
                        DB::table('whatsapp_opt_outs')->insertOrIgnore(['phone_e164' => $phone, 'created_at' => $guest->whatsapp_opted_out_at]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_opt_outs');
    }
};
