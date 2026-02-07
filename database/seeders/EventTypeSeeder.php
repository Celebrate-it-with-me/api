<?php

namespace Database\Seeders;

use App\Models\EventPlan;
use App\Models\EventType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        EventType::query()->truncate();
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        EventType::query()->insert([
            ['name' => 'Quinceañera', 'slug' => 'quinceanera', 'icon' => 'party-popper', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Wedding', 'slug' => 'wedding', 'icon' => 'heart', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Birthday', 'slug' => 'birthday', 'icon' => 'cake', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Baby Shower', 'slug' => 'baby-shower', 'icon' => 'baby', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Graduation', 'slug' => 'graduation', 'icon' => 'graduation-cap', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
