<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the admin-panel user (role `admin`).
 *
 * Credentials come from DEFAULT_ADMIN_EMAIL / DEFAULT_ADMIN_PASSWORD
 * (config `app.admin_user`). Outside production, if they are not set, a local
 * default is used so the admin panel can be opened right after `migrate --seed`.
 * In production nothing is created unless both variables are set.
 */
class AdminUserSeeder extends Seeder
{
    public const LOCAL_EMAIL = 'admin@cwm.test';
    public const LOCAL_PASSWORD = 'password';

    public function run(): void
    {
        $email = config('app.admin_user.email');
        $password = config('app.admin_user.password');

        if (! $email || ! $password) {
            if (app()->isProduction()) {
                $this->command?->warn('DEFAULT_ADMIN_EMAIL / DEFAULT_ADMIN_PASSWORD not set: admin user skipped.');

                return;
            }

            $email = self::LOCAL_EMAIL;
            $password = self::LOCAL_PASSWORD;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrador CWM',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        // Older databases got an admin with the well-known password 'password' from a migration that no longer
        // does that. If it is still untouched, replace it with the configured one;
        // a password that was changed on purpose is never overwritten.
        if (! $admin->wasRecentlyCreated
            && $password !== self::LOCAL_PASSWORD
            && Hash::check(self::LOCAL_PASSWORD, $admin->password)) {
            $admin->forceFill(['password' => Hash::make($password)])->save();
        }

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        $this->command?->info("Admin user ready: {$email}");
    }
}
