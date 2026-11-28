<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\PreShipmentCancellationOrderFamily;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadata;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreShipmentCancellationFinanceDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        foreach ([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.central',
            'preship_cancellations.review',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        config(['pre_shipment_cancellation.enabled' => true]);
    }

    private function financeUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $user->givePermissionTo([
            'preship_cancellations.central',
            'preship_cancellations.review',
            'preship_cancellations.view',
        ]);

        return $user;
    }

    private function cancellationCase(array $overrides = []): PreShipmentCancellationRequest
    {
        $uniq = (string) random_int(100000, 999999);
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RBP-FIN-'.$uniq,
            'product_name' => 'Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 2000,
            'cashfree_payment_id' => 'cf_fin_'.$uniq,
            'created_by' => $actor->id,
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-RBP-FIN-'.$uniq,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP-FIN-'.$uniq,
            'idempotency_key' => 'psc-fin-'.$order->id,
            'payload_hash' => hash('sha256', 'RBP-FIN-'.$uniq),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => 2000,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        return PreShipmentCancellationRequest::query()->create(array_merge([
            'reference_no' => 'PSC-FIN-'.$uniq,
            'order_id' => $order->id,
            'source_id' => 'RBP-FIN-'.$uniq,
            'order_family' => PreShipmentCancellationOrderFamily::Rbp,
            'status' => PreShipmentCancellationStatus::FinanceReview,
            'cancellation_reason' => 'Customer requested cancel before ship.',
            'submitted_by_user_id' => $actor->id,
            'order_total_amount' => 2000,
            'external_tender_amount' => 1500,
            'wallet_tender_amount' => 500,
            'already_refunded_amount' => 0,
            'maximum_refundable_amount' => 2000,
            'requested_refund_amount' => 2000,
            'split_tender' => true,
            'execution_hold' => true,
            'execution_hold_reason' => 'Split-tender Phase 2 requires manual Finance review and execution.',
            'idempotency_key' => 'psc-fin-display-'.$order->id,
            'committed_at' => now(),
        ], $overrides));
    }

    public function test_show_displays_wallet_refund_preference_and_notes(): void
    {
        $case = $this->cancellationCase([
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', 'Call confirmed with customer.'),
        ]);

        $this->actingAs($this->financeUser())
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertOk()
            ->assertSee('Refund status', false)
            ->assertSee('Unlinked / Action Required', false)
            ->assertSee('Wallet credit', false)
            ->assertSee('Call confirmed with customer.', false)
            ->assertSee('Cashfree and Wallet amounts require manual Finance review', false)
            ->assertSee('2,000.00', false);
    }

    public function test_show_displays_opm_refund_preference(): void
    {
        $case = $this->cancellationCase([
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('opm', null),
            'split_tender' => false,
            'execution_hold' => false,
            'wallet_tender_amount' => 0,
            'external_tender_amount' => 2000,
        ]);

        $this->actingAs($this->financeUser())
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertOk()
            ->assertSee('Original payment method (OPM)', false);
    }

    public function test_operator_notes_are_html_escaped_on_show(): void
    {
        $case = $this->cancellationCase([
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', '<script>alert(1)</script>'),
        ]);

        $html = $this->actingAs($this->financeUser())
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_legacy_case_without_metadata_renders_without_preference_section(): void
    {
        $case = $this->cancellationCase([
            'blocker_reason' => null,
        ]);

        $this->actingAs($this->financeUser())
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertOk()
            ->assertSee('Cancellation reason', false)
            ->assertDontSee('Refund preference', false)
            ->assertSee('manual Finance review', false);
    }

    public function test_malformed_metadata_does_not_break_show(): void
    {
        $case = $this->cancellationCase([
            'blocker_reason' => 'psc_meta:{not-json',
        ]);

        $this->actingAs($this->financeUser())
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertOk()
            ->assertDontSee('Refund preference', false);
    }

    public function test_central_queue_displays_refund_preference_labels(): void
    {
        $wallet = $this->cancellationCase([
            'reference_no' => 'PSC-FIN-WALLET',
            'source_id' => 'RBP-FIN-W1',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', 'note'),
            'idempotency_key' => 'psc-fin-w',
        ]);
        $opm = $this->cancellationCase([
            'reference_no' => 'PSC-FIN-OPM',
            'source_id' => 'RBP-FIN-O1',
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('opm', null),
            'idempotency_key' => 'psc-fin-o',
        ]);
        $legacy = $this->cancellationCase([
            'reference_no' => 'PSC-FIN-LEG',
            'source_id' => 'RBP-FIN-L1',
            'blocker_reason' => null,
            'idempotency_key' => 'psc-fin-l',
        ]);

        $response = $this->actingAs($this->financeUser())
            ->get(route('pre-shipment-cancellations.central-queue'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Wallet credit', $html);
        $this->assertStringContainsString('Original payment method (OPM)', $html);
        $this->assertStringContainsString($wallet->reference_no, $html);
        $this->assertStringContainsString($opm->reference_no, $html);
        $this->assertStringContainsString($legacy->reference_no, $html);
    }

    public function test_unauthorized_user_cannot_view_finance_case_show(): void
    {
        $case = $this->cancellationCase([
            'blocker_reason' => PreShipmentCancellationOperatorMetadata::encode('wallet', 'secret note'),
        ]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('pre-shipment-cancellations.show', $case))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_view_central_queue(): void
    {
        $this->cancellationCase();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('pre-shipment-cancellations.central-queue'))
            ->assertForbidden();
    }
}
