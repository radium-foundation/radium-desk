<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Services\Commerce\CommerceCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerceSites;
use Tests\TestCase;

class CommerceCatalogApiTest extends TestCase
{
    use InteractsWithCommerceSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableCommerce();
    }

    public function test_catalog_brands_endpoint_returns_site_scoped_brands(): void
    {
        [$site, $key] = $this->createSiteWithApiKey(['site_id' => 'rdserviceonline']);

        CommerceCatalogBrand::query()->create([
            'commerce_site_id' => $site->id,
            'external_slug' => 'mantra',
            'display_name' => 'Mantra',
            'sort_order' => 1,
            'is_enabled' => true,
        ]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/brands',
            'rdserviceonline',
            $key,
        );

        $response->assertOk()
            ->assertJson([
                'site_id' => 'rdserviceonline',
                'api_version' => '1',
                'brands' => [
                    ['slug' => 'mantra', 'display_name' => 'Mantra'],
                ],
            ]);
    }

    public function test_catalog_models_endpoint_returns_models_for_brand(): void
    {
        $fixture = $this->createCatalogFixture();

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/brands/mantra/models',
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertOk()
            ->assertJsonPath('brand.slug', 'mantra')
            ->assertJsonPath('models.0.display_name', 'MFS110');
    }

    public function test_catalog_plans_endpoint_returns_rd_and_amc_plans(): void
    {
        $fixture = $this->createCatalogFixture([
            'amc' => ['selling_price' => '500.00', 'publish_price' => '590.00'],
        ]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/models/'.$fixture['model']->id.'/plans',
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertOk()
            ->assertJsonPath('model.display_name', 'MFS110')
            ->assertJsonCount(2, 'plans');
    }

    public function test_quote_endpoint_returns_golden_rd_only_amounts(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/models/'.$fixture['model']->id
            .'/quote?rd_plan_id='.$fixture['rdPlan']->id,
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertOk()
            ->assertJson([
                'site_id' => 'rdserviceonline',
                'api_version' => '1',
                'subtotal' => 1200.0,
                'tax_total' => 216.0,
                'payable_amount' => 1416.0,
                'cashfree_amount' => 1416,
            ]);
    }

    public function test_quote_endpoint_returns_golden_rd_plus_amc_amounts(): void
    {
        $fixture = $this->createCatalogFixture([
            'amc' => ['selling_price' => '500.00', 'publish_price' => '590.00'],
        ]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/models/'.$fixture['model']->id
            .'/quote?rd_plan_id='.$fixture['rdPlan']->id
            .'&amc_plan_id='.$fixture['amcPlan']->id,
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertOk()
            ->assertJson([
                'payable_amount' => 2006.0,
                'cashfree_amount' => 2006,
            ]);
    }

    public function test_quote_endpoint_ignores_client_submitted_price_parameters(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/models/'.$fixture['model']->id
            .'/quote?rd_plan_id='.$fixture['rdPlan']->id
            .'&payable_amount=1&client_total=1',
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertOk()
            ->assertJsonPath('payable_amount', 1416);
    }

    public function test_wrong_brand_model_relationship_returns_validation_error(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);

        $otherBrand = CommerceCatalogBrand::query()->create([
            'commerce_site_id' => $fixture['site']->id,
            'external_slug' => 'morpho',
            'display_name' => 'Morpho',
            'sort_order' => 2,
            'is_enabled' => true,
        ]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/brands/morpho/models',
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertOk()->assertJsonCount(0, 'models');
    }

    public function test_model_from_other_site_is_not_quotable(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null, 'site_id' => 'site-a']);
        [$siteB, $keyB] = $this->createSiteWithApiKey(['site_id' => 'site-b']);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/site-b/catalog/models/'.$fixture['model']->id
            .'/quote?rd_plan_id='.$fixture['rdPlan']->id,
            'site-b',
            $keyB,
        );

        $response->assertStatus(422);
    }

    public function test_route_site_must_match_authenticated_site(): void
    {
        [$siteA, $keyA] = $this->createSiteWithApiKey(['site_id' => 'site-a']);
        $this->createSiteWithApiKey(['site_id' => 'site-b']);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/site-b/catalog/brands',
            'site-a',
            $keyA,
        );

        $response->assertUnauthorized();
    }

    public function test_commerce_disabled_blocks_catalog_routes(): void
    {
        $this->enableCommerce(false);
        [, $key] = $this->createSiteWithApiKey(['site_id' => 'rdserviceonline']);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/brands',
            'rdserviceonline',
            $key,
        );

        $response->assertStatus(503);
    }

    public function test_amc_plan_used_as_rd_plan_is_rejected(): void
    {
        $fixture = $this->createCatalogFixture([
            'amc' => ['selling_price' => '500.00', 'publish_price' => '590.00'],
        ]);

        $response = $this->commerceGet(
            '/api/v1/commerce/sites/rdserviceonline/catalog/models/'.$fixture['model']->id
            .'/quote?rd_plan_id='.$fixture['amcPlan']->id,
            'rdserviceonline',
            $fixture['apiKey'],
        );

        $response->assertStatus(422);
    }
}
