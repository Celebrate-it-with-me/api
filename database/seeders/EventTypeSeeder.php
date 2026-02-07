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
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'wedding',
                'name' => 'Wedding',
                'icon' => 'heart',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'birthday',
                'name' => 'Birthday',
                'icon' => 'cake',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'baby-shower',
                'name' => 'Baby Shower',
                'icon' => 'baby',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'graduation',
                'name' => 'Graduation',
                'icon' => 'graduation-cap',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['slug'], ['name', 'icon', 'updated_at']);
    }
}
