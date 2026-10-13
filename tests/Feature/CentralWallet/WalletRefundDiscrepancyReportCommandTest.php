<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WalletRefundDiscrepancyReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_discrepancy_report_command_emits_json_summary(): void
    {
        $exitCode = Artisan::call('central-wallet:wallet-refund-discrepancy-report', ['--limit' => 1]);
        $this->assertSame(0, $exitCode);

        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('generated_at', $decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('rows', $decoded);
    }
}
