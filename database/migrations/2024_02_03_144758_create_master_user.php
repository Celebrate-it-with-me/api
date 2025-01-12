<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::query()->firstOrCreate([
            'name' => 'Henry Carmenate',
            'email' => 'henrycarmenateg@gmail.com',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::query()->where('name', 'Henry Carmenate')
            ->where('email', 'henrycarmenateg@gmail.com')
            ->delete();
    }
};
