<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\PilotRefundMigrationManifestLoader;
use App\CentralWallet\Application\PilotRefundMigrationPreflightGate;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotRefundMigrationRef67398ManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ref_67398_radiumbox_manifest_validates(): void
    {
        config(['central_wallet.pilot_refund_migration.allowed_refund_ids' => '393']);

        $path = base_path('tests/fixtures/cw-pilot-refund-ref-67398-manifest.json');
        $manifest = app(PilotRefundMigrationManifestLoader::class)->load($path);

        $this->assertSame([393], $manifest['allowed_refund_ids']);
        $this->assertSame('radiumbox.com', $manifest['rows'][0]['site']);
        $this->assertSame(
            app(PilotRefundMigrationManifestLoader::class)->hashRows($manifest['rows']),
            $manifest['manifest_rows_sha256'],
        );
    }

    public function test_ref_67398_live_preflight_has_no_duplicate_ledger_blocker(): void
    {
        config(['central_wallet.pilot_refund_migration.allowed_refund_ids' => '393']);

        $path = base_path('tests/fixtures/cw-pilot-refund-ref-67398-manifest.json');

        $cwid = 'b2222222-2222-4222-8222-222222222222';
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => 'a1111111-1111-4111-8111-111111111111',
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $order = Order::query()->create([
            'id' => 990393,
            'order_id' => 'RBP858',
            'customer_id' => 'a1111111-1111-4111-8111-111111111111',
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => '119.00',
            'created_by' => $user->id,
        ]);

        RefundRequest::query()->create([
            'id' => 393,
            'order_id' => $order->id,
            'reference_no' => 'REF-67398',
            'refund_amount' => '119.00',
            'amount' => '119.00',
            'reason' => 'pilot preflight',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $user->id,
            'execution_transaction_id' => '9002579',
            'refund_transaction_id' => '9002579',
        ]);

        $blockers = app(PilotRefundMigrationPreflightGate::class)->evaluateManifest($path, false);
        $codes = array_column($blockers, 'code');
        $this->assertNotContains('duplicate_ledger_credit', $codes);
    }
}
