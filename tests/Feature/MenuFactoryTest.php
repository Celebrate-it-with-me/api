<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a valid menu with a title and an event', function () {
    $menu = Menu::factory()->create();

    expect($menu->title)->not->toBeEmpty()
        ->and($menu->event_id)->not->toBeNull();
});
