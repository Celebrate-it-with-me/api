<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        EventType::query()->upsert([
            [
                'slug' => 'quinceanera',
                'name' => 'Quinceañera',
                'icon' => 'party-popper',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'wedding',
                'name' => 'Wedding',
                'icon' => 'heart',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'birthday',
                'name' => 'Birthday',
                'icon' => 'cake',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'baby-shower',
                'name' => 'Baby Shower',
                'icon' => 'baby',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'graduation',
                'name' => 'Graduation',
                'icon' => 'graduation-cap',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['slug'], ['name', 'icon', 'is_active', 'updated_at']);
    }
}
