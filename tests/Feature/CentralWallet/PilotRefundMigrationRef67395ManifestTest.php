<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\PilotRefundMigrationManifestLoader;
use App\CentralWallet\Application\PilotRefundMigrationPreflightGate;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotRefundMigrationRef67395ManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ref_67395_manifest_validates_and_preflight_has_no_duplicate_ledger_blocker(): void
    {
        config(['central_wallet.pilot_refund_migration.allowed_refund_ids' => '390']);

        $path = base_path('tests/fixtures/cw-pilot-refund-ref-67395-manifest.json');
        $loader = app(PilotRefundMigrationManifestLoader::class);
        $manifest = $loader->load($path);

        $this->assertSame([390], $manifest['allowed_refund_ids']);
        $this->assertSame('052076aa8f61071e68d46b204f6bdee9c560a98a6fe99841c77f45b6df1a5305', $manifest['manifest_rows_sha256']);

        $cwid = 'd1852026-d8c2-4af4-818c-d3151549f614';
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $order = Order::query()->create([
            'id' => 62928,
            'order_id' => 'RD17641',
            'customer_id' => 'c51247d0-bb8a-4924-8389-8503ba32f5bc',
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => '597.00',
            'created_by' => $user->id,
        ]);

        RefundRequest::query()->create([
            'id' => 390,
            'order_id' => $order->id,
            'reference_no' => 'REF-67395',
            'refund_amount' => '597.00',
            'amount' => '597.00',
            'reason' => 'pilot preflight',
            'status' => \App\Enums\RefundStatus::Closed,
            'approved_refund_method' => \App\Enums\ApprovedRefundMethod::Wallet,
            'requested_by' => $user->id,
        ]);

        $blockers = app(PilotRefundMigrationPreflightGate::class)->evaluateManifest($path, false);
        $codes = array_column($blockers, 'code');
        $this->assertNotContains('duplicate_ledger_credit', $codes);
    }
}
