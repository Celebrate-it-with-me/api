<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->in('Feature');

// Configuración para tests Unit
pest()->extend(TestCase::class)
    ->in('Unit');

// Hydration tests create users/events and read event_types/event_plans: they must migrate by themselves.
// (A uses(...)->in('Unit/Services/Hydrate') inside tests/Helpers is resolved against that folder and never matched.)
pest()->use(RefreshDatabase::class)
    ->in('Unit/Services/Hydrate');

/*
|--------------------------------------------------------------------------
| Hydration Helpers
|--------------------------------------------------------------------------
*/

// Cargar helpers de Hydration
require_once __DIR__ . '/Helpers/HydrationHelpers.php';
require_once __DIR__ . '/Feature/WhatsApp/WhatsAppHelpers.php';

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function something()
{
    // ..
}
