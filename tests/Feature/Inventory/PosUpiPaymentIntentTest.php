<?php

namespace Tests\Feature\Inventory;

use App\Enums\FinanceJournalSourceType;
use App\Enums\InventoryReservationStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\PosPaymentIntentStatus;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankAccountUpiProfile;
use App\Models\FinanceJournal;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\PosPaymentIntent;
use App\Models\User;
use App\Services\Finance\PosSaleJournalService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Pos\PosUpiIntentService;
use App\Services\Pos\PosUpiVerificationService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosUpiPaymentIntentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hardware;

    private User $verifier;

    private InventoryBranch $branchA;

    private InventoryBranch $branchB;

    private PosUpiIntentService $intents;

    private PosUpiVerificationService $verifications;

    private InventoryStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->hardware = User::factory()->create(['is_active' => true]);
        $this->hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->verifier->givePermissionTo(RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY);

        $this->branchA = InventoryBranch::query()->create([
            'code' => 'UPIA',
            'name' => 'UPI Branch A',
            'is_active' => true,
        ]);
        $this->branchB = InventoryBranch::query()->create([
            'code' => 'UPIB',
            'name' => 'UPI Branch B',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->hardware->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->intents = app(PosUpiIntentService::class);
        $this->verifications = app(PosUpiVerificationService::class);
        $this->stock = app(InventoryStockService::class);
    }

    public function test_permission_is_seeded_but_assigned_to_no_role(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY]);
        $this->assertFalse($this->admin->can(RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY));
        $this->assertFalse($this->hardware->can(RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY));
        $this->assertTrue($this->verifier->can(RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY));
    }

    public function test_create_persists_intent_without_a_sale_and_reuses_recovery_key(): void
    {
        $account = $this->receivingAccount();
        $product = $this->quantityProduct();
        $this->stock->stockInQuantity($product, $this->branchA, 4, $this->hardware);

        $payload = $this->cart($product, $account);

        $first = $this->intents->create(...$payload, saleIdempotencyKey: 'upi-recover-1');
        $second = $this->intents->create(...$payload, saleIdempotencyKey: 'upi-recover-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->tr, $second->tr);
        $this->assertSame($first->upi_uri, $second->upi_uri);
        $this->assertSame(PosPaymentIntentStatus::Pending, $first->status);
        $this->assertSame(0, InventorySale::query()->count());
        $this->assertSame(3, (int) $product->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->branchA->id)->value('reserved_qty'));
        $this->assertStringStartsWith('upi://pay?', $first->upi_uri);
        $this->assertStringContainsString('am='.$first->amount, $first->upi_uri);
        $this->assertStringContainsString('tr='.$first->tr, $first->upi_uri);
        $this->assertStringContainsString('cu=INR', $first->upi_uri);
    }

    public function test_disabled_profile_and_missing_account_are_rejected(): void
    {
        $disabled = $this->receivingAccount(enabled: false);
        $product = $this->quantityProduct('OTG-OFF');
        $this->stock->stockInQuantity($product, $this->branchA, 2, $this->hardware);

        $this->expectException(ValidationException::class);
        $this->intents->create(...$this->cart($product, $disabled));
    }

    public function test_hardware_can_create_but_cannot_open_verify_queue(): void
    {
        $account = $this->receivingAccount();
        $product = $this->quantityProduct('OTG-HTTP');
        $this->stock->stockInQuantity($product, $this->branchA, 2, $this->hardware);

        $this->actingAs($this->hardware)
            ->post(route('pos.counter.store'), $this->counterPayload($product, $account, 'UPI'))
            ->assertRedirect();

        $this->assertSame(1, PosPaymentIntent::query()->count());
        $this->assertSame(0, InventorySale::query()->count());

        $intent = PosPaymentIntent::query()->firstOrFail();
        $this->actingAs($this->hardware)->get(route('pos.upi.intents.show', $intent))->assertOk();
        $this->actingAs($this->hardware)->get(route('pos.upi.payments.index'))->assertForbidden();
        $this->actingAs($this->hardware)->get(route('pos.upi.payments.show', $intent))->assertForbidden();
        $this->actingAs($this->admin)->get(route('pos.upi.payments.index'))->assertForbidden();
    }

    public function test_verification_requires_utr_attestation_and_matching_amount(): void
    {
        $intent = $this->pendingIntent();

        $this->actingAs($this->verifier)
            ->from(route('pos.upi.payments.show', $intent))
            ->post(route('pos.upi.payments.verify', $intent), [
                'confirmed_amount' => $intent->amount,
                'bank_checked' => '1',
            ])
            ->assertSessionHasErrors('utr');

        $this->actingAs($this->verifier)
            ->from(route('pos.upi.payments.show', $intent))
            ->post(route('pos.upi.payments.verify', $intent), [
                'utr' => 'UTRTEST1',
                'confirmed_amount' => $intent->amount,
            ])
            ->assertSessionHasErrors('bank_checked');

        $this->actingAs($this->verifier)
            ->from(route('pos.upi.payments.show', $intent))
            ->post(route('pos.upi.payments.verify', $intent), [
                'utr' => 'UTRTEST1',
                'confirmed_amount' => '1.00',
                'bank_checked' => '1',
            ])
            ->assertSessionHasErrors('confirmed_amount');

        $this->assertSame(0, InventorySale::query()->count());
        $this->assertSame(PosPaymentIntentStatus::Pending, $intent->fresh()->status);
    }

    public function test_successful_verification_completes_sale_stock_and_journal_once(): void
    {
        $account = $this->receivingAccount();
        $qty = $this->quantityProduct('OTG-OK');
        $serialized = $this->serializedProduct();
        $this->stock->stockInQuantity($qty, $this->branchA, 5, $this->hardware);
        $this->stock->stockInSerialized($serialized, $this->branchA, ['UPI-SER-1'], $this->hardware);

        $intent = $this->intents->create(
            branch: $this->branchA,
            customer: ['name' => 'UPI Buyer', 'phone' => '9999900100'],
            lines: [
                ['product_id' => $qty->id, 'qty' => 2],
                ['product_id' => $serialized->id, 'qty' => 1, 'serials' => ['UPI-SER-1']],
            ],
            receivingBankAccountId: $account->id,
            actor: $this->hardware,
        );

        $this->assertSame(3, (int) $qty->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(2, (int) $qty->balances()->where('branch_id', $this->branchA->id)->value('reserved_qty'));

        $sale = $this->verifications->confirm($intent, $this->verifier, '  utr abc 12 ', true, $intent->amount);
        $again = $this->verifications->confirm($intent, $this->verifier, 'UTRABC12', true, $intent->amount);

        $this->assertSame($sale->id, $again->id);
        $this->assertSame(1, InventorySale::query()->count());
        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame('UPI', $sale->payment_method);
        $this->assertSame('UTRABC12', $sale->payment_reference);
        $this->assertSame($intent->id, $sale->upi_intent_id);
        $this->assertSame(PosPaymentIntentStatus::Completed, $intent->fresh()->status);
        $this->assertSame($sale->id, $intent->fresh()->sale_id);
        $this->assertSame(3, (int) $qty->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(0, (int) $qty->balances()->where('branch_id', $this->branchA->id)->value('reserved_qty'));
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'UPI-SER-1')->value('status'));
        $this->assertSame(InventoryReservationStatus::Consumed, $intent->reservation()->value('status'));
        $this->assertSame(1, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::PosSale->value)->where('source_id', $sale->id)->where('idempotency_key', 'pos_sale:'.$sale->id)->count());

        $this->actingAs($this->admin)
            ->get(route('pos.sales.show', $sale))
            ->assertOk()
            ->assertSee('refund the customer through UPI', false);
    }

    public function test_duplicate_utr_is_rejected(): void
    {
        $first = $this->pendingIntent('OTG-DUP1');
        $second = $this->pendingIntent('OTG-DUP2');

        $this->verifications->confirm($first, $this->verifier, 'SAMEUTR9', true, $first->amount);

        $this->expectException(ValidationException::class);
        $this->verifications->confirm($second, $this->verifier, 'sameutr9', true, $second->amount);
    }

    public function test_abandon_releases_stock_and_blocks_later_verify(): void
    {
        $intent = $this->pendingIntent('OTG-ABD');
        $productId = $intent->cart_payload['lines'][0]['product_id'];

        $this->intents->abandon($intent, $this->hardware, 'Customer left');

        $this->assertSame(PosPaymentIntentStatus::Abandoned, $intent->fresh()->status);
        $this->assertSame(2, (int) InventoryProduct::query()->findOrFail($productId)->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(0, InventorySale::query()->count());

        $this->expectException(ValidationException::class);
        $this->verifications->confirm($intent->fresh(), $this->verifier, 'UTRABANDON', true, $intent->amount);
    }

    public function test_expired_intent_is_abandoned_on_access(): void
    {
        $intent = $this->pendingIntent('OTG-EXP');
        $intent->update(['expires_at' => now()->subMinute()]);

        $expired = $this->intents->refreshExpiry($intent, $this->hardware);

        $this->assertSame(PosPaymentIntentStatus::Abandoned, $expired->status);
        $this->assertSame('Expired', $expired->abandon_reason);
    }

    public function test_finance_failure_leaves_intent_pending_and_stock_reserved(): void
    {
        $intent = $this->pendingIntent('OTG-FIN');
        $productId = $intent->cart_payload['lines'][0]['product_id'];

        $this->mock(PosSaleJournalService::class, function ($mock) {
            $mock->shouldReceive('postForSale')->andThrow(ValidationException::withMessages([
                'finance' => 'Finance accounts are not configured.',
            ]));
        });

        try {
            app(PosUpiVerificationService::class)->confirm($intent, $this->verifier, 'UTRFINFAIL', true, $intent->amount);
            $this->fail('Verification should have failed closed.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, InventorySale::query()->count());
        $this->assertSame(PosPaymentIntentStatus::Pending, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->utr);
        $this->assertSame(1, (int) InventoryProduct::query()->findOrFail($productId)->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(1, (int) InventoryProduct::query()->findOrFail($productId)->balances()->where('branch_id', $this->branchA->id)->value('reserved_qty'));
    }

    public function test_cash_card_and_bank_transfer_still_complete_immediately(): void
    {
        $product = $this->quantityProduct('OTG-REG');
        $this->stock->stockInQuantity($product, $this->branchA, 6, $this->hardware);

        foreach (['Cash', 'Card', 'Bank Transfer'] as $method) {
            $this->actingAs($this->hardware)
                ->post(route('pos.counter.store'), $this->counterPayload($product, null, $method, qty: 1))
                ->assertRedirect();
        }

        $this->assertSame(3, InventorySale::query()->count());
        $this->assertSame(0, PosPaymentIntent::query()->count());
        $this->assertSame(['Bank Transfer', 'Card', 'Cash'], InventorySale::query()->orderBy('payment_method')->pluck('payment_method')->all());
    }

    public function test_cashfree_pos_tender_stays_immediate_and_upi_code_does_not_call_cashfree(): void
    {
        $product = $this->quantityProduct('OTG-CF');
        $this->stock->stockInQuantity($product, $this->branchA, 1, $this->hardware);

        $this->actingAs($this->hardware)
            ->post(route('pos.counter.store'), $this->counterPayload($product, null, 'Cashfree', qty: 1))
            ->assertRedirect();

        $this->assertSame(1, InventorySale::query()->where('payment_method', 'Cashfree')->count());
        $this->assertSame(0, PosPaymentIntent::query()->count());

        foreach ([
            app_path('Services/Pos/PosUpiIntentService.php'),
            app_path('Services/Pos/PosUpiVerificationService.php'),
            app_path('Support/Pos/PosUpiUriBuilder.php'),
            app_path('Http/Controllers/Pos/UpiIntentController.php'),
            app_path('Http/Controllers/Pos/UpiPaymentVerificationController.php'),
        ] as $path) {
            $this->assertStringNotContainsString('Cashfree', (string) file_get_contents($path));
        }
    }

    public function test_hardware_cannot_verify_another_branch_intent(): void
    {
        $account = $this->receivingAccount();
        $product = $this->quantityProduct('OTG-ISO');
        $this->stock->stockInQuantity($product, $this->branchB, 2, $this->admin);

        $intent = $this->intents->create(
            ...$this->cart($product, $account, $this->admin, $this->branchB),
        );

        $this->actingAs($this->hardware)->get(route('pos.upi.intents.show', $intent))->assertForbidden();
        $this->actingAs($this->verifier)
            ->post(route('pos.upi.payments.verify', $intent), [
                'utr' => 'UTRISO1',
                'confirmed_amount' => $intent->amount,
                'bank_checked' => '1',
            ])
            ->assertRedirect(route('pos.sales.show', $intent->fresh()->sale_id));
    }

    public function test_cancel_after_verified_upi_restores_stock_and_keeps_intent_completed(): void
    {
        $intent = $this->pendingIntent('OTG-CAN');
        $sale = $this->verifications->confirm($intent, $this->verifier, 'UTRCANCEL1', true, $intent->amount);

        $this->actingAs($this->admin)
            ->post(route('pos.sales.cancel', $sale), ['reason' => 'Customer changed mind'])
            ->assertRedirect();

        $this->assertSame(InventorySaleStatus::Cancelled, $sale->fresh()->status);
        $this->assertSame(PosPaymentIntentStatus::Completed, $intent->fresh()->status);
        $this->assertSame(2, (int) InventoryProduct::query()->findOrFail($intent->cart_payload['lines'][0]['product_id'])->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(2, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::PosSale->value)->where('source_id', $sale->id)->count());
    }

    /**
     * @return array{
     *     branch: InventoryBranch,
     *     customer: array{name: string, phone: string},
     *     lines: list<array{product_id: int, qty: int}>,
     *     receivingBankAccountId: int,
     *     actor: User
     * }
     */
    private function cart(
        InventoryProduct $product,
        FinanceBankAccount $account,
        ?User $actor = null,
        ?InventoryBranch $branch = null,
        int $qty = 1,
    ): array {
        return [
            'branch' => $branch ?? $this->branchA,
            'customer' => ['name' => 'UPI Buyer', 'phone' => '9999900100'],
            'lines' => [[
                'product_id' => $product->id,
                'qty' => $qty,
            ]],
            'receivingBankAccountId' => $account->id,
            'actor' => $actor ?? $this->hardware,
        ];
    }

    private function pendingIntent(string $sku = 'OTG-UPI'): PosPaymentIntent
    {
        $account = $this->receivingAccount();
        $product = $this->quantityProduct($sku);
        $this->stock->stockInQuantity($product, $this->branchA, 2, $this->hardware);

        return $this->intents->create(...$this->cart($product, $account));
    }

    /**
     * @return array<string, mixed>
     */
    private function counterPayload(
        InventoryProduct $product,
        ?FinanceBankAccount $account,
        string $method,
        int $qty = 1,
    ): array {
        return [
            'branch_id' => $this->branchA->id,
            'customer_name' => 'Counter Buyer',
            'customer_phone' => '9999900200',
            'payment_method' => $method,
            'receiving_bank_account_id' => $account?->id,
            'discount' => 0,
            'idempotency_key' => $method.'-'.$product->sku.'-'.uniqid(),
            'lines' => [[
                'product_id' => $product->id,
                'qty' => $qty,
                'unit_price' => $product->unit_price,
                'discount' => 0,
            ]],
        ];
    }

    private function receivingAccount(bool $enabled = true): FinanceBankAccount
    {
        $account = FinanceBankAccount::query()->create([
            'bank_name' => 'Test Receive Bank',
            'account_name' => 'Test Collection',
            'last_four' => '0000',
            'is_active' => true,
        ]);

        FinanceBankAccountUpiProfile::query()->create([
            'finance_bank_account_id' => $account->id,
            'vpa' => 'test-desk@upi',
            'payee_name' => 'Test Desk Payee',
            'is_enabled' => $enabled,
        ]);

        return $account->fresh('upiProfile') ?? $account;
    }

    private function quantityProduct(string $sku = 'OTG-UPI'): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'OTG '.$sku,
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);
    }

    private function serializedProduct(): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => 'MFS-UPI',
            'name' => 'Mantra UPI',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
