<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** email => name */
    public const USERS = [
        'marisol@cwm.test' => 'Marisol Fernández',
        'carlos@cwm.test' => 'Carlos Fernández',
        'lucia@cwm.test' => 'Lucía Pérez',
        'daniel@cwm.test' => 'Daniel Rojas',
    ];

    public function run(): void
    {
        foreach (self::USERS as $email => $name) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                ]
            );

            // Skip the first-login onboarding for the demo accounts.
            DB::table('users')->where('id', $user->id)->update(['is_first_login' => false]);

            DB::table('user_preferences')->updateOrInsert(
                ['user_id' => $user->id],
                ['language' => 'es', 'timezone' => 'America/New_York', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $this->command?->info('Demo users ready (password: '.self::PASSWORD.').');
    }
}
