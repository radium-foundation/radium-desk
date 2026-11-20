<?php

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\StatutoryInvoice\Support\StatutoryCommerceMintMysqlGate;
use Tests\Feature\StatutoryInvoice\Support\StatutoryCommerceMintMysqlPrepareLock;

require __DIR__.'/mysql_statutory_commerce_mint_bootstrap.php';

$options = getopt('', ['suffix::', 'scenario::']);
$suffix = (string) ($options['suffix'] ?? bin2hex(random_bytes(4)));
$scenario = (string) ($options['scenario'] ?? 'single_order');

$lockHandle = StatutoryCommerceMintMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        StatutoryCommerceMintMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = statutory_commerce_mint_mysql_credentials_from_env();
statutory_commerce_mint_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! StatutoryCommerceMintMysqlGate::isAllowedDatabase((string) $connected->d)) {
    fwrite(STDERR, "Prepare refused: unexpected database.\n");
    exit(2);
}

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}

(new FinanceMasterDataSeeder)->run();
(new RolePermissionSeeder)->run();

$actor = User::factory()->create([
    'name' => 'Statutory Mint Conc Finance',
    'email' => 'stat-mint-conc-'.$suffix.'@example.com',
    'is_active' => true,
]);
$actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

/**
 * @param  array<string, mixed>  $suffixSeed
 * @return array{order: CommerceOrder, conversion: CommerceOrderBuyerGstB2cConversion}
 */
$buildProductB2cOrder = static function (string $orderSuffix): array {
    $sourceId = 'RBP-MINT-CONC-'.$orderSuffix;
    $order = CommerceOrder::query()->create([
        'order_no' => 'CO-MINT-CONC-'.$orderSuffix,
        'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
        'source_type' => 'commerce_order',
        'source_id' => $sourceId,
        'source_order_id' => $sourceId,
        'idempotency_key' => 'test:stat-mint-conc:'.$orderSuffix,
        'payload_hash' => hash('sha256', 'stat-mint-conc:'.$orderSuffix),
        'status' => CommerceOrderStatus::Validated,
        'invoice_eligible' => true,
        'payment_status' => 'paid',
        'currency' => 'INR',
        'customer_name' => 'Buyer',
        'buyer_gstin' => '07AAICB5215F2ZX',
        'billing_address_structured' => ['state' => 'Rajasthan', 'city' => 'Jodhpur', 'pincode' => '342001'],
        'shipping_address_structured' => ['state' => 'Rajasthan', 'city' => 'Jodhpur', 'pincode' => '342001'],
        'place_of_supply_state' => 'Rajasthan',
        'billing_state' => 'Rajasthan',
        'branch_code' => 'DELHI-RETAIL',
        'order_value' => 118,
        'taxable_value' => 100,
        'tax_total' => 18,
        'ordered_at' => now(),
        'received_at' => now(),
    ]);

    $order->items()->create([
        'line_no' => 1,
        'sku' => 'MFS110-UAT',
        'description' => 'Mantra MFS110 product line',
        'hsn_sac' => '84716050',
        'qty' => 1,
        'unit_price' => 100,
        'gst_percentage' => 18,
        'taxable_value' => 100,
        'tax_total' => 18,
        'igst' => 18,
        'line_total' => 118,
    ]);

    CommerceOrderBuyerGstVerification::query()->create([
        'commerce_order_id' => $order->id,
        'submitted_gstin' => '07AAICB5215F2ZX',
        'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
        'registration_status' => 'Suspended',
        'review_reason' => 'inactive_registration',
        'provider_payload_hash' => hash('sha256', 'fixture:'.$orderSuffix),
    ]);

    $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order->fresh());
    if ($conversion === null) {
        fwrite(STDERR, "Failed to create approved B2C conversion for order suffix {$orderSuffix}.\n");
        exit(2);
    }

    return [
        'order' => $order->fresh(['items']),
        'conversion' => $conversion->fresh(),
    ];
};

$context = [
    'suffix' => $suffix,
    'scenario' => $scenario,
    'actor_user_id' => $actor->id,
];

if ($scenario === 'dual_order') {
    $first = $buildProductB2cOrder($suffix.'-a');
    $secondBuilt = $buildProductB2cOrder($suffix.'-b');
    $secondBuilt['order']->forceFill([
        'branch_code' => 'MUMBAI',
        'billing_state' => 'Maharashtra',
        'place_of_supply_state' => 'Maharashtra',
        'billing_address_structured' => ['state' => 'Maharashtra', 'city' => 'Mumbai', 'pincode' => '400001'],
    ])->save();
    $secondOrder = $secondBuilt['order']->fresh(['items']);
    $context['commerce_orders'] = [
        ['commerce_order_id' => $first['order']->id, 'source_id' => $first['order']->source_id],
        ['commerce_order_id' => $secondOrder->id, 'source_id' => $secondOrder->source_id],
    ];
} elseif ($scenario === 'preissued_invoice') {
    $built = $buildProductB2cOrder($suffix);
    $invoice = app(StatutoryInvoiceService::class)->issueFromCommerceOrder($built['order']->fresh(['items']), $actor);
    $context['commerce_order_id'] = $built['order']->id;
    $context['conversion_id'] = $built['conversion']->id;
    $context['statutory_invoice_id'] = $invoice->id;
    $context['invoice_number'] = $invoice->invoice_number;
    $context['baseline_publication_count'] = RadiumBoxStatutoryReceiptPublication::query()->count();
    $context['baseline_invoice_count'] = StatutoryInvoice::query()->count();
} else {
    $built = $buildProductB2cOrder($suffix);
    $context['commerce_order_id'] = $built['order']->id;
    $context['commerce_order_source_id'] = $built['order']->source_id;
    $context['conversion_id'] = $built['conversion']->id;
}

echo json_encode($context, JSON_THROW_ON_ERROR);
