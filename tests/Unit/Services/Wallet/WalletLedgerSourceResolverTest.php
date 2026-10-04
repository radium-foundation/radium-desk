<?php

namespace Tests\Unit\Services\Wallet;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Wallet\WalletLedgerSource;
use App\Services\Wallet\WalletLedgerSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerSourceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_rdservice_net_orders_use_central_wallet_source(): void
    {
        $incident = $this->incidentWithOrderCode('RN153');

        $this->assertSame(
            WalletLedgerSource::CentralWallet,
            app(WalletLedgerSourceResolver::class)->resolveForIncident($incident),
        );
    }

    public function test_rdservice_in_orders_use_central_wallet_source(): void
    {
        $incident = $this->incidentWithOrderCode('RD3147');

        $this->assertSame(
            WalletLedgerSource::CentralWallet,
            app(WalletLedgerSourceResolver::class)->resolveForIncident($incident),
        );
    }

    public function test_radiumbox_orders_use_legacy_wallet_source(): void
    {
        $incident = $this->incidentWithOrderCode('RB317');

        $this->assertSame(
            WalletLedgerSource::RadiumBoxLegacy,
            app(WalletLedgerSourceResolver::class)->resolveForIncident($incident),
        );
    }

    private function incidentWithOrderCode(string $orderCode): Incident
    {
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => $orderCode,
            'customer_email' => 'resolver@example.com',
            'customer_name' => 'Resolver Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-RESOLVER-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Resolver case',
            'description' => 'Resolver case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
