<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use Illuminate\Database\Seeder;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $rd = ServiceCategory::query()->updateOrCreate(
            ['code' => 'rd_service'],
            [
                'name' => 'RD Service',
                'sort_order' => 10,
                'is_active' => true,
                'legacy_admin_attribute_id' => 1,
            ],
        );

        $amc = ServiceCategory::query()->updateOrCreate(
            ['code' => 'amc'],
            [
                'name' => 'AMC / Warranty',
                'sort_order' => 20,
                'is_active' => true,
                'legacy_admin_attribute_id' => 2,
            ],
        );

        $custom = ServiceCategory::query()->updateOrCreate(
            ['code' => 'custom'],
            [
                'name' => 'Custom Service',
                'sort_order' => 90,
                'is_active' => true,
                'legacy_admin_attribute_id' => null,
            ],
        );

        ServiceItem::query()->updateOrCreate(
            ['code' => 'DEV-RD-1Y'],
            [
                'category_id' => $rd->id,
                'name' => 'RD Service (Dev Sample)',
                'description' => 'Synthetic one-year RD service for development testing.',
                'duration_label' => '1 Year Unlimited',
                'sac_code' => '998313',
                'gst_rate' => 18,
                'price_ex_gst' => 422.88,
                'price_incl_gst' => 499.00,
                'is_active' => true,
            ],
        );

        ServiceItem::query()->updateOrCreate(
            ['code' => 'DEV-AMC-1Y'],
            [
                'category_id' => $amc->id,
                'name' => 'AMC (Dev Sample)',
                'description' => 'Synthetic one-year AMC for development testing.',
                'duration_label' => '1 Year Standard',
                'sac_code' => '998313',
                'gst_rate' => 18,
                'price_ex_gst' => 84.75,
                'price_incl_gst' => 100.00,
                'is_active' => true,
            ],
        );

        ServiceItem::query()->updateOrCreate(
            ['code' => 'DEV-CUSTOM-MS'],
            [
                'category_id' => $custom->id,
                'name' => 'Custom Market Study (Dev Sample)',
                'description' => 'Synthetic custom service line for development testing.',
                'duration_label' => null,
                'sac_code' => '998596',
                'gst_rate' => 18,
                'price_ex_gst' => 50000.00,
                'price_incl_gst' => 59000.00,
                'is_active' => true,
            ],
        );
    }
}
