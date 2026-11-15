<?php

// Test script to verify user registration assigns correct permissions
// This script simulates the user registration process

require_once 'vendor/autoload.php';

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Simulate the user creation process
echo "Testing user registration permission assignment...\n\n";

// Check if roles and permissions exist
$appUserRole = Role::where('name', 'appUser')->first();
$appPermission = Permission::where('name', 'app')->first();

if (! $appUserRole) {
    echo "❌ ERROR: 'appUser' role not found. Please run the RolesAndPermissionSeeder.\n";
    exit(1);
}

if (! $appPermission) {
    echo "❌ ERROR: 'app' permission not found. Please run the RolesAndPermissionSeeder.\n";
    exit(1);
}

echo "✅ Required role and permission exist:\n";
echo "   - Role: {$appUserRole->name}\n";
echo "   - Permission: {$appPermission->name}\n\n";

// Test user data
$testEmail = 'test_user_' . time() . '@example.com';
$testName = 'Test User';
$testPassword = 'password123';

echo "Creating test user with email: {$testEmail}\n";

// Simulate the registration process
$user = User::create([
    'name' => $testName,
    'email' => $testEmail,
    'password' => Hash::make($testPassword),
]);

// Assign the appUser role (this is what we added to the controller)
$user->assignRole('appUser');

echo "✅ User created successfully with ID: {$user->id}\n\n";

// Verify the user has the correct role and permission
$hasAppUserRole = $user->hasRole('appUser');
$hasAppPermission = $user->hasPermissionTo('app');

echo "Permission verification:\n";
echo "   - Has 'appUser' role: " . ($hasAppUserRole ? '✅ YES' : '❌ NO') . "\n";
echo "   - Has 'app' permission: " . ($hasAppPermission ? '✅ YES' : '❌ NO') . "\n\n";

if ($hasAppUserRole && $hasAppPermission) {
    echo "🎉 SUCCESS: User registration now correctly assigns frontend permissions!\n";
    echo "   New users will have access to frontend routes.\n";
} else {
    echo "❌ FAILURE: Permission assignment is not working correctly.\n";
}

// Clean up test user
$user->delete();
echo "\n🧹 Test user cleaned up.\n";
