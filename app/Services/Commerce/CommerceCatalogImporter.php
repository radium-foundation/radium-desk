<?php

namespace App\Services\Commerce;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Models\Commerce\CommerceSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CommerceCatalogImporter
{
    /**
     * @return array{site_id: string, brands: int, models: int, plans: int}
     */
    public function importFromFixture(string $fixturePath): array
    {
        if (! File::exists($fixturePath)) {
            throw new InvalidArgumentException("Commerce catalog fixture not found: {$fixturePath}");
        }

        $payload = json_decode(File::get($fixturePath), true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Commerce catalog fixture must contain valid JSON.');
        }

        return DB::transaction(function () use ($payload) {
            $siteId = (string) ($payload['site_id'] ?? '');
            if ($siteId === '') {
                throw new InvalidArgumentException('Commerce catalog fixture requires site_id.');
            }

            $site = CommerceSite::query()->firstOrCreate(
                ['site_id' => $siteId],
                [
                    'display_name' => $siteId,
                    'allowed_origins' => [],
                    'is_enabled' => true,
                ],
            );

            $brandCount = 0;
            $modelCount = 0;
            $planCount = 0;

            foreach ($payload['brands'] ?? [] as $brandData) {
                $brand = CommerceCatalogBrand::query()->updateOrCreate(
                    [
                        'commerce_site_id' => $site->id,
                        'external_slug' => (string) $brandData['external_slug'],
                    ],
                    [
                        'display_name' => (string) $brandData['display_name'],
                        'sort_order' => (int) ($brandData['sort_order'] ?? 0),
                        'is_enabled' => (bool) ($brandData['is_enabled'] ?? true),
                    ],
                );
                $brandCount++;

                foreach ($brandData['models'] ?? [] as $modelData) {
                    $model = CommerceCatalogModel::query()->updateOrCreate(
                        [
                            'brand_id' => $brand->id,
                            'display_name' => (string) $modelData['display_name'],
                        ],
                        [
                            'sort_order' => (int) ($modelData['sort_order'] ?? 0),
                            'is_enabled' => (bool) ($modelData['is_enabled'] ?? true),
                        ],
                    );
                    $modelCount++;

                    foreach ($modelData['plans'] ?? [] as $planData) {
                        CommerceCatalogPlan::query()->updateOrCreate(
                            [
                                'model_id' => $model->id,
                                'plan_type' => (string) $planData['plan_type'],
                                'short_name' => (string) $planData['short_name'],
                            ],
                            [
                                'display_name' => (string) $planData['display_name'],
                                'selling_price' => (string) $planData['selling_price'],
                                'publish_price' => (string) $planData['publish_price'],
                                'regular_price' => (string) ($planData['regular_price'] ?? '0'),
                                'hsn_code' => $planData['hsn_code'] ?? null,
                                'sort_order' => (int) ($planData['sort_order'] ?? 0),
                                'is_enabled' => (bool) ($planData['is_enabled'] ?? true),
                                'legacy_reference' => $planData['legacy_reference'] ?? null,
                            ],
                        );
                        $planCount++;
                    }
                }
            }

            return [
                'site_id' => $siteId,
                'brands' => $brandCount,
                'models' => $modelCount,
                'plans' => $planCount,
            ];
        });
    }
}
