<?php
    
    use App\Models\GuestCompanion;
    use App\Models\PartyMember;
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
        Schema::create('guest_companions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('main_guest_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->enum('confirmed', ['pending', 'yes', 'no'])->default('pending');
            $table->dateTime('confirmed_at')->nullable()->default(null);
            $table->timestamps();
        });
        
        $partyMembers = PartyMember::query()->get();
        
        foreach ($partyMembers as $partyMember) {
            $names = explode(' ', $partyMember->name, 2);
            $guestCompanion = new GuestCompanion();
            $guestCompanion->first_name = $names[0];
            $guestCompanion->last_name = $names[1];
            $guestCompanion->main_guest_id = $partyMember->main_guest_id;
            $guestCompanion->email = $partyMember->email;
            $guestCompanion->phone_number = $partyMember->phone_number;
            $guestCompanion->confirmed_at = null;
            $guestCompanion->save();
        }
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_companions');
    }
};
