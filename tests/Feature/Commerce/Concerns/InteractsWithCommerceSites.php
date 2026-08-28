<?php

namespace Tests\Feature\Commerce\Concerns;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Models\Commerce\CommerceSite;
use App\Models\Commerce\CommerceSiteApiKey;
use App\Services\Commerce\CommerceSiteApiKeyHasher;
use Illuminate\Support\Str;

trait InteractsWithCommerceSites
{
    protected function enableCommerce(bool $enabled = true): void
    {
        config(['commerce.enabled' => $enabled]);
    }

    /**
     * @param  array<string, mixed>  $siteAttributes
     * @return array{0: CommerceSite, 1: string}
     */
    protected function createSiteWithApiKey(array $siteAttributes = []): array
    {
        $hasher = app(CommerceSiteApiKeyHasher::class);
        $plainKey = 'rdsk_test_'.Str::random(40);

        $site = CommerceSite::query()->create([
            'site_id' => $siteAttributes['site_id'] ?? 'test-site-'.Str::lower(Str::random(8)),
            'display_name' => $siteAttributes['display_name'] ?? 'Test Commerce Site',
            'allowed_origins' => $siteAttributes['allowed_origins'] ?? ['https://example.test'],
            'is_enabled' => $siteAttributes['is_enabled'] ?? true,
        ]);

        CommerceSiteApiKey::query()->create([
            'commerce_site_id' => $site->id,
            'name' => 'test',
            'key_hash' => $hasher->hash($plainKey),
            'key_prefix' => substr($plainKey, 0, 8),
            'is_active' => true,
        ]);

        return [$site, $plainKey];
    }

    /**
     * @param  array{
     *     rd?: array{selling_price: float|string, publish_price: float|string, regular_price?: float|string},
     *     amc?: array{selling_price: float|string, publish_price: float|string, regular_price?: float|string}|null,
     *     site_id?: string
     * }  $options
     * @return array{
     *     site: CommerceSite,
     *     apiKey: string,
     *     brand: CommerceCatalogBrand,
     *     model: CommerceCatalogModel,
     *     rdPlan: CommerceCatalogPlan,
     *     amcPlan: CommerceCatalogPlan|null
     * }
     */
    protected function createCatalogFixture(array $options = []): array
    {
        [$site, $apiKey] = $this->createSiteWithApiKey([
            'site_id' => $options['site_id'] ?? 'rdserviceonline',
            'display_name' => 'RD Service Online',
        ]);

        $brand = CommerceCatalogBrand::query()->create([
            'commerce_site_id' => $site->id,
            'external_slug' => 'mantra',
            'display_name' => 'Mantra',
            'sort_order' => 1,
            'is_enabled' => true,
        ]);

        $model = CommerceCatalogModel::query()->create([
            'brand_id' => $brand->id,
            'display_name' => 'MFS110',
            'sort_order' => 1,
            'is_enabled' => true,
        ]);

        $rd = $options['rd'] ?? [
            'selling_price' => '1000.00',
            'publish_price' => '1180.00',
            'regular_price' => '200.00',
        ];

        $rdPlan = CommerceCatalogPlan::query()->create([
            'model_id' => $model->id,
            'plan_type' => CommerceCatalogPlan::TYPE_RD,
            'display_name' => '1 Year RD',
            'short_name' => '1Y',
            'selling_price' => $rd['selling_price'],
            'publish_price' => $rd['publish_price'],
            'regular_price' => $rd['regular_price'] ?? '0.00',
            'hsn_code' => '998313',
            'sort_order' => 1,
            'is_enabled' => true,
        ]);

        $amcPlan = null;
        if (array_key_exists('amc', $options) && $options['amc'] !== null) {
            $amc = $options['amc'];
            $amcPlan = CommerceCatalogPlan::query()->create([
                'model_id' => $model->id,
                'plan_type' => CommerceCatalogPlan::TYPE_AMC,
                'display_name' => '1 Year AMC',
                'short_name' => 'AMC1Y',
                'selling_price' => $amc['selling_price'],
                'publish_price' => $amc['publish_price'],
                'regular_price' => $amc['regular_price'] ?? '0.00',
                'hsn_code' => '998313',
                'sort_order' => 2,
                'is_enabled' => true,
            ]);
        }

        return compact('site', 'apiKey', 'brand', 'model', 'rdPlan', 'amcPlan');
    }

    protected function commerceGet(string $uri, string $siteId, string $plainKey)
    {
        return $this->withHeaders([
            'X-Site-Id' => $siteId,
            'Authorization' => 'Bearer '.$plainKey,
        ])->getJson($uri);
    }
}
