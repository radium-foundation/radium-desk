<?php

namespace Tests\Unit\HardwareFulfilment;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiveProductionOverlayRegressionTest extends TestCase
{
    #[Test]
    public function dashboard_js_preserves_historical_order_and_hardware_live_hooks(): void
    {
        $source = file_get_contents(base_path('resources/js/pages/dashboard.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString("import { initHistoricalOrderSummary } from '../historical-order-summary'", $source);
        $this->assertStringContainsString('initHistoricalOrderSummary();', $source);
        $this->assertStringContainsString("import { applyHardwareLivePayload } from '../hardware-dashboard-live'", $source);
        $this->assertStringContainsString("document.addEventListener('hardware-dashboard:updated'", $source);
    }

    #[Test]
    public function historical_order_summary_assets_exist(): void
    {
        $this->assertFileExists(base_path('resources/js/historical-order-summary.js'));
        $this->assertFileExists(base_path('resources/views/dashboard/partials/historical-order-summary-modal.blade.php'));
    }

    #[Test]
    public function hardware_operational_row_preserves_live_date_and_b2b_fields(): void
    {
        $source = file_get_contents(base_path('app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('lastActionDateIst', $source);
        $this->assertStringContainsString('isB2bCustomer', $source);
        $this->assertStringContainsString('compactTimelineDisplay', $source);
        $this->assertStringContainsString('matchesWorkspaceFilter', $source);
    }

    #[Test]
    public function hardware_workspace_blade_preserves_date_column_and_b2b_badge(): void
    {
        $rowPartial = file_get_contents(base_path('resources/views/dashboard/partials/hardware-workspace-row.blade.php'));
        $workspace = file_get_contents(base_path('resources/views/dashboard/partials/hardware-workspace.blade.php'));

        $this->assertIsString($rowPartial);
        $this->assertIsString($workspace);
        $this->assertStringContainsString('dashboard-hardware-datetime-cell', $rowPartial);
        $this->assertStringContainsString('isB2bCustomer', $rowPartial);
        $this->assertStringContainsString('dashboard-hardware-b2b', $rowPartial);
        $this->assertStringContainsString('dashboard-hardware-datetime-header', $workspace);
        $this->assertStringContainsString('id="dashboard-hardware-body"', $workspace);
    }

    #[Test]
    public function hardware_shipment_eligibility_preserves_configurable_variant_display(): void
    {
        $source = file_get_contents(base_path('app/Services/HardwareFulfilment/HardwareShipmentEligibility.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('HardwareConfigurableVariantDisplay', $source);
        $this->assertStringContainsString('providerTrackNormalized', $source);
    }

    #[Test]
    public function shiprocket_tracking_sync_defaults_to_disabled_without_explicit_env(): void
    {
        $previous = getenv('SHIPROCKET_TRACKING_SYNC_ENABLED');
        putenv('SHIPROCKET_TRACKING_SYNC_ENABLED');

        $enabled = config('shipping.tracking.sync_enabled');

        if ($previous === false) {
            putenv('SHIPROCKET_TRACKING_SYNC_ENABLED');
        } else {
            putenv('SHIPROCKET_TRACKING_SYNC_ENABLED='.$previous);
        }

        $this->assertFalse($enabled);
    }
}
