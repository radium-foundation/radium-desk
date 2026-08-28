<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\CommerceCatalogBrand;
use App\Models\Commerce\CommerceCatalogModel;
use App\Models\Commerce\CommerceCatalogPlan;
use App\Services\Commerce\CommerceRdRenewalPricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerceSites;
use Tests\TestCase;

class CommerceRdRenewalPricingEngineTest extends TestCase
{
    use InteractsWithCommerceSites;
    use RefreshDatabase;

    private CommerceRdRenewalPricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(CommerceRdRenewalPricingEngine::class);
    }

    public function test_golden_rd_only_quote(): void
    {
        $fixture = $this->createCatalogFixture([
            'amc' => null,
        ]);

        $quote = $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan'],
        );

        $this->assertSame(1200.0, $quote->subtotal);
        $this->assertSame(216.0, $quote->taxTotal);
        $this->assertSame(1416.0, $quote->payableAmount);
        $this->assertSame(1416, $quote->cashfreeAmount);
        $this->assertSame(180.0, $quote->taxBreakdown['rd_amc_gst']);
        $this->assertSame(36.0, $quote->taxBreakdown['duration_gst']);
    }

    public function test_golden_rd_plus_amc_quote(): void
    {
        $fixture = $this->createCatalogFixture([
            'amc' => [
                'selling_price' => '500.00',
                'publish_price' => '590.00',
            ],
        ]);

        $quote = $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan'],
            $fixture['amcPlan'],
        );

        $this->assertSame(1700.0, $quote->subtotal);
        $this->assertSame(306.0, $quote->taxTotal);
        $this->assertSame(2006.0, $quote->payableAmount);
        $this->assertSame(2006, $quote->cashfreeAmount);
    }

    public function test_golden_fractional_quote_uses_legacy_formula_and_rounds_cashfree_amount(): void
    {
        $fixture = $this->createCatalogFixture([
            'rd' => [
                'selling_price' => '1000.50',
                'publish_price' => '1180.59',
                'regular_price' => '50.25',
            ],
            'amc' => null,
        ]);

        $quote = $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan'],
        );

        $this->assertEqualsWithDelta(1050.75, $quote->subtotal, 0.001);
        $this->assertEqualsWithDelta(189.135, $quote->taxTotal, 0.001);
        $this->assertEqualsWithDelta(1239.885, $quote->payableAmount, 0.001);
        $this->assertSame(1240, $quote->cashfreeAmount);
    }

    public function test_zero_regular_price_has_no_duration_tax(): void
    {
        $fixture = $this->createCatalogFixture([
            'rd' => [
                'selling_price' => '1000.00',
                'publish_price' => '1180.00',
                'regular_price' => '0.00',
            ],
            'amc' => null,
        ]);

        $quote = $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan'],
        );

        $this->assertSame(1000.0, $quote->subtotal);
        $this->assertSame(180.0, $quote->taxTotal);
        $this->assertSame(1180.0, $quote->payableAmount);
        $this->assertSame(0.0, $quote->taxBreakdown['duration_gst']);
    }

    public function test_rd_plan_from_another_model_is_rejected(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);

        $otherModel = CommerceCatalogModel::query()->create([
            'brand_id' => $fixture['brand']->id,
            'display_name' => 'Other Model',
            'sort_order' => 2,
            'is_enabled' => true,
        ]);

        $foreignRd = CommerceCatalogPlan::query()->create([
            'model_id' => $otherModel->id,
            'plan_type' => CommerceCatalogPlan::TYPE_RD,
            'display_name' => 'Foreign RD',
            'short_name' => 'FRD',
            'selling_price' => '10.00',
            'publish_price' => '11.80',
            'regular_price' => '0.00',
            'sort_order' => 1,
            'is_enabled' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $foreignRd,
        );
    }

    public function test_amc_plan_from_another_model_is_rejected(): void
    {
        $fixture = $this->createCatalogFixture([
            'amc' => [
                'selling_price' => '500.00',
                'publish_price' => '590.00',
            ],
        ]);

        $otherModel = CommerceCatalogModel::query()->create([
            'brand_id' => $fixture['brand']->id,
            'display_name' => 'Other Model',
            'sort_order' => 2,
            'is_enabled' => true,
        ]);

        $foreignAmc = CommerceCatalogPlan::query()->create([
            'model_id' => $otherModel->id,
            'plan_type' => CommerceCatalogPlan::TYPE_AMC,
            'display_name' => 'Foreign AMC',
            'short_name' => 'FAMC',
            'selling_price' => '1.00',
            'publish_price' => '1.18',
            'regular_price' => '0.00',
            'sort_order' => 1,
            'is_enabled' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan'],
            $foreignAmc,
        );
    }

    public function test_disabled_brand_is_rejected(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);
        $fixture['brand']->update(['is_enabled' => false]);

        $this->expectException(ValidationException::class);

        $this->engine->quote(
            $fixture['site'],
            $fixture['brand']->fresh(),
            $fixture['model'],
            $fixture['rdPlan'],
        );
    }

    public function test_disabled_model_is_rejected(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);
        $fixture['model']->update(['is_enabled' => false]);

        $this->expectException(ValidationException::class);

        $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model']->fresh(),
            $fixture['rdPlan'],
        );
    }

    public function test_disabled_rd_plan_is_rejected(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);
        $fixture['rdPlan']->update(['is_enabled' => false]);

        $this->expectException(ValidationException::class);

        $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan']->fresh(),
        );
    }

    public function test_quote_snapshot_is_immutable_pricing_payload(): void
    {
        $fixture = $this->createCatalogFixture(['amc' => null]);

        $quote = $this->engine->quote(
            $fixture['site'],
            $fixture['brand'],
            $fixture['model'],
            $fixture['rdPlan'],
        );

        $snapshot = $quote->toSnapshot();

        $this->assertSame('rdserviceonline', $snapshot['site_id']);
        $this->assertSame(1416.0, $snapshot['payable_amount']);
        $this->assertSame(1416, $snapshot['cashfree_amount']);
        $this->assertSame(1, $snapshot['pricing_version']);
    }
}
