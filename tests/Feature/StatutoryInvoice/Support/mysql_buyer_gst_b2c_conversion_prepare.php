<?php

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\StatutoryInvoice\Support\BuyerGstB2cConversionMysqlGate;
use Tests\Feature\StatutoryInvoice\Support\BuyerGstB2cConversionMysqlPrepareLock;

require __DIR__.'/mysql_buyer_gst_b2c_conversion_bootstrap.php';

$options = getopt('', ['suffix::']);
$suffix = (string) ($options['suffix'] ?? bin2hex(random_bytes(4)));

$lockHandle = BuyerGstB2cConversionMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        BuyerGstB2cConversionMysqlPrepareLock::release($lockHandle);
    }
);

$credentials = buyer_gst_b2c_conversion_mysql_credentials_from_env();
buyer_gst_b2c_conversion_mysql_boot_app($credentials);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! BuyerGstB2cConversionMysqlGate::isAllowedDatabase((string) $connected->d)) {
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

$actor = User::factory()->create([
    'name' => 'GST B2C Conc Finance',
    'email' => 'gst-b2c-conc-'.$suffix.'@example.com',
    'is_active' => true,
]);

$sourceId = 'RBP-CONC-'.$suffix;
$order = CommerceOrder::query()->create([
    'order_no' => 'CO-CONC-'.$suffix,
    'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
    'source_type' => 'commerce_order',
    'source_id' => $sourceId,
    'source_order_id' => $sourceId,
    'idempotency_key' => 'test:gst-b2c-conc:'.$suffix,
    'payload_hash' => hash('sha256', 'gst-b2c-conc:'.$suffix),
    'status' => CommerceOrderStatus::Validated,
    'invoice_eligible' => true,
    'payment_status' => 'paid',
    'currency' => 'INR',
    'customer_name' => 'Buyer',
    'buyer_gstin' => '07AAICB5215F2ZX',
    'billing_address_structured' => [
        'state' => 'Rajasthan',
        'city' => 'Jodhpur',
        'pincode' => '304012',
    ],
    'place_of_supply_state' => 'Rajasthan',
    'billing_state' => 'Rajasthan',
    'order_value' => 100,
    'taxable_value' => 84.75,
    'tax_total' => 15.25,
    'ordered_at' => now(),
    'received_at' => now(),
]);

$verification = CommerceOrderBuyerGstVerification::query()->create([
    'commerce_order_id' => $order->id,
    'submitted_gstin' => '07AAICB5215F2ZX',
    'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
    'registration_status' => 'Suspended',
    'review_reason' => 'inactive_registration',
    'provider_payload_hash' => hash('sha256', 'fixture:'.$suffix),
    'submitted_billing_address_structured' => $order->billing_address_structured,
]);

echo json_encode([
    'suffix' => $suffix,
    'commerce_order_id' => $order->id,
    'commerce_order_source_id' => $sourceId,
    'verification_id' => $verification->id,
    'actor_user_id' => $actor->id,
], JSON_THROW_ON_ERROR);
