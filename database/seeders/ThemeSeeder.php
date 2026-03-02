<?php

namespace Database\Seeders;

use App\Models\Theme\Theme;
use App\Models\Theme\ThemeBaseAsset;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ThemeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /**
         * 1) BASIC (FREE)
         */
        $basic = Theme::query()->updateOrCreate(
            ['slug' => 'basic'],
            [
                'name' => 'Basic',
                'tier' => 'free',
                'is_published' => true,
                'default_config' => [
                    'global' => [
                        'font_heading' => 'system',
                        'font_body' => 'system',
                        'accent' => '#EC4899',
                    ],
                    'section' => [
                        'hero' => [
                            'title' => 'My Event',
                            'subtitle' => 'You are invited',
                        ],
                    ],
                ],
            ]
        );
        ThemeBaseAsset::query()->updateOrCreate(
            [
                'theme_id' => $basic->id,
                'section' => 'hero',
                'asset_type' => 'background',
                'sort_order' => 0,
            ],
            [
                'provider' => 'upload',
                'disk' => 'themes',
                'file_path' => 'basic/hero/hero_basic.jpg',
                'file_name' => 'hero_basic.jpg',
                'mime_type' => 'image/jpeg',
                'meta' => [
                    'recommended_position' => '50% 50%',
                    'recommended_zoom' => 'cover',
                ],
                'is_active' => true,
            ]
        );
        
        /**
         * 2) HABANA NIGHTS (PRO)
         */
        $hn = Theme::query()->updateOrCreate(
            ['slug' => 'habana-nights'],
            [
                'name' => 'Habana Nights',
                'tier' => 'pro',
                'is_published' => true,
                'default_config' => [
                    'global' => [
                        'font_heading' => 'Cinzel',
                        'font_script' => 'Great Vibes',
                        'font_body' => 'Montserrat',
                        'hn_gold' => '#D4AF37',
                        'hn_cream' => '#F8F1E7',
                        'hn_midnight' => '#0B1220',
                        'primary' => '#EC4899',
                    ],
                    'section' => [
                        'hero' => [
                            'name' => 'Elizabeth Perez',
                            'date' => '04 · 18 · 2026',
                            'subtitle' => 'MIS QUINCE',
                            'mobile' => [
                                'bg_size' => '200%',
                                'bg_position' => '50% 90%',
                            ],
                        ],
                    ],
                ],
            ]
        );
        
        ThemeBaseAsset::query()->updateOrCreate(
            [
                'theme_id' => $hn->id,
                'section' => 'hero',
                'asset_type' => 'background',
                'sort_order' => 0,
            ],
            [
                'provider' => 'upload',
                'disk' => 'themes',
                'file_path' => 'habana-nights/hero/hero_2644.jpg',
                'file_name' => 'hero_2644.jpg',
                'mime_type' => 'image/jpeg',
                'meta' => [
                    'recommended_position_desktop' => '50% 50%',
                    'recommended_position_mobile' => '50% 90%',
                    'recommended_mobile_zoom' => '200%',
                ],
                'is_active' => true,
            ]
        );
    }
}
