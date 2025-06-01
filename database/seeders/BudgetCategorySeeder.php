<?php

namespace Database\Seeders;

use App\Models\BudgetCategory;
use Illuminate\Database\Seeder;

class BudgetCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Venue', 'slug' => 'venue', 'description' => 'Cost of venue rental and associated services.'],
            ['name' => 'Catering', 'slug' => 'catering', 'description' => 'Food and drink for the event.'],
            ['name' => 'Decoration', 'slug' => 'decoration', 'description' => 'Floral arrangements, props, and themed decoration.'],
            ['name' => 'Entertainment', 'slug' => 'entertainment', 'description' => 'Music, DJ, performers or other forms of entertainment.'],
            ['name' => 'Photography', 'slug' => 'photography', 'description' => 'Photography and video services.'],
            ['name' => 'Outfits', 'slug' => 'outfits', 'description' => 'Clothing and accessories for the event.'],
            ['name' => 'Transportation', 'slug' => 'transportation', 'description' => 'Vehicles, fuel, and transport logistics.'],
            ['name' => 'Miscellaneous', 'slug' => 'miscellaneous', 'description' => 'Other uncategorized expenses.'],
        ];

        foreach ($categories as $category) {
            BudgetCategory::query()->firstOrCreate([
                'slug' => $category['slug'],
            ], [
                'name' => $category['name'],
                'description' => $category['description'],
                'is_default' => true,
            ]);
        }

        $this->command->info('✅ Default budget categories seeded!');
    }
}
