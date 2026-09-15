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

    #[Test]
    public function hardware_navigation_uses_live_scope_and_filter_model(): void
    {
        $nav = file_get_contents(base_path('resources/views/dashboard/partials/hardware-workspace-nav.blade.php'));
        $cases = file_get_contents(base_path('resources/views/dashboard/partials/recent-service-cases.blade.php'));
        $liveJs = file_get_contents(base_path('resources/js/hardware-dashboard-live.js'));

        $this->assertIsString($nav);
        $this->assertIsString($cases);
        $this->assertIsString($liveJs);
        $this->assertStringContainsString('data-hardware-scope-count', $nav);
        $this->assertStringContainsString('data-hardware-filter-count', $nav);
        $this->assertStringContainsString('hw_scope', $nav);
        $this->assertStringContainsString('hw_filter', $nav);
        $this->assertStringContainsString('hardware-workspace-nav', $cases);
        $this->assertStringContainsString('name="hw_scope"', $cases);
        $this->assertStringContainsString('data-hardware-scope-count', $liveJs);
        $this->assertStringContainsString('data-hardware-filter-count', $liveJs);
        $this->assertStringNotContainsString('href*="hw_queue=', $liveJs);
    }

    #[Test]
    public function hardware_live_service_uses_workspace_scope_enum_not_string_queue(): void
    {
        $source = file_get_contents(base_path('app/Services/HardwareFulfilment/HardwareDashboardLiveService.php'));
        $serialController = file_get_contents(base_path('app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php'));

        $this->assertIsString($source);
        $this->assertIsString($serialController);
        $this->assertStringContainsString('HardwareWorkspaceScope $scope', $source);
        $this->assertStringContainsString('HardwareWorkspaceFilter $filter', $source);
        $this->assertStringContainsString('scope_counts', $source);
        $this->assertStringContainsString('filter_counts', $source);
        $this->assertStringContainsString('resolveScope($request)', $serialController);
        $this->assertStringNotContainsString("livePayload(\$user, [(int) \$fulfilment->id], '')", $serialController);
    }

    #[Test]
    public function dashboard_live_hardware_route_is_preserved(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString("Route::get('/dashboard/live/hardware'", $routes);
        $this->assertStringContainsString("->name('dashboard.live.hardware')", $routes);
    }

    #[Test]
    public function hardware_configurable_variant_display_class_exists(): void
    {
        $this->assertFileExists(base_path('app/Support/HardwareFulfilment/HardwareConfigurableVariantDisplay.php'));
    }
}
