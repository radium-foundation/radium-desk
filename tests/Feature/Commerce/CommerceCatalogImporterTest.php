<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Services\Commerce\CommerceCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceCatalogImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_loads_synthetic_fixture_without_legacy_db(): void
    {
        $result = app(CommerceCatalogImporter::class)->importFromFixture(
            database_path('fixtures/commerce/catalog-rdserviceonline.json'),
        );

        $this->assertSame('rdserviceonline', $result['site_id']);
        $this->assertSame(1, $result['brands']);
        $this->assertSame(1, $result['models']);
        $this->assertSame(2, $result['plans']);

        $brand = CommerceCatalogBrand::query()
            ->whereHas('site', fn ($q) => $q->where('site_id', 'rdserviceonline'))
            ->where('external_slug', 'mantra')
            ->first();

        $this->assertNotNull($brand);

        $plans = CommerceCatalogPlan::query()
            ->whereHas('model', fn ($q) => $q->where('brand_id', $brand->id))
            ->get();

        $this->assertCount(2, $plans);
        $this->assertTrue($plans->contains(fn ($plan) => $plan->plan_type === 'rd'));
        $this->assertTrue($plans->contains(fn ($plan) => $plan->plan_type === 'amc'));
    }
}
