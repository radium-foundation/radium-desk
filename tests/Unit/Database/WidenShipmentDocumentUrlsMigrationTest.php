<?php

namespace Tests\Unit\Database;

use App\Enums\CommerceOrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WidenShipmentDocumentUrlsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_27_120000_widen_shipment_document_urls';

    public function test_up_allows_manifest_and_label_urls_longer_than_1024_characters(): void
    {
        $this->assertDocumentUrlColumnsAreText();

        $long = 'https://example.test/doc.pdf?'.Str::repeat('a', 1100);

        $shipmentId = $this->insertMinimalShipmentRow([
            'label_url' => $long,
            'manifest_url' => $long,
        ]);

        $row = DB::table('shipments')->where('id', $shipmentId)->first();
        $this->assertSame(strlen($long), strlen((string) $row->label_url));
        $this->assertSame(strlen($long), strlen((string) $row->manifest_url));
    }

    public function test_down_succeeds_when_all_document_urls_are_within_1024_characters(): void
    {
        $short = 'https://example.test/short.pdf';

        $this->insertMinimalShipmentRow([
            'label_url' => $short,
            'manifest_url' => $short,
        ]);

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--force' => true,
        ]);

        $this->assertDocumentUrlColumnsAreVarchar1024();
    }

    public function test_down_aborts_when_manifest_url_exceeds_1024_characters(): void
    {
        $long = 'https://example.test/manifest.pdf?'.Str::repeat('m', 1100);

        $this->insertMinimalShipmentRow([
            'label_url' => 'https://example.test/label.pdf',
            'manifest_url' => $long,
        ]);

        try {
            Artisan::call('migrate:rollback', [
                '--path' => 'database/migrations/'.self::MIGRATION.'.php',
                '--force' => true,
            ]);
            $this->fail('Expected rollback to abort');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('manifest_url', $exception->getMessage());
            $this->assertStringContainsString('longer than 1024', $exception->getMessage());
        }

        $this->assertDocumentUrlColumnsAreText();
    }

    public function test_down_aborts_when_label_url_exceeds_1024_characters(): void
    {
        $long = 'https://example.test/label.pdf?'.Str::repeat('l', 1100);

        $this->insertMinimalShipmentRow([
            'label_url' => $long,
            'manifest_url' => null,
        ]);

        try {
            Artisan::call('migrate:rollback', [
                '--path' => 'database/migrations/'.self::MIGRATION.'.php',
                '--force' => true,
            ]);
            $this->fail('Expected rollback to abort');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('label_url', $exception->getMessage());
        }

        $this->assertDocumentUrlColumnsAreText();
    }

    private function assertDocumentUrlColumnsAreText(): void
    {
        $label = $this->columnType('label_url');
        $manifest = $this->columnType('manifest_url');

        $this->assertNotNull($label);
        $this->assertNotNull($manifest);
        $this->assertStringContainsString('text', strtolower($label));
        $this->assertStringContainsString('text', strtolower($manifest));
    }

    private function assertDocumentUrlColumnsAreVarchar1024(): void
    {
        $label = strtolower((string) $this->columnType('label_url'));
        $manifest = strtolower((string) $this->columnType('manifest_url'));

        $this->assertTrue(
            str_contains($label, 'varchar') || str_contains($label, 'character'),
            $label,
        );
        $this->assertTrue(
            str_contains($manifest, 'varchar') || str_contains($manifest, 'character'),
            $manifest,
        );
    }

    private function columnType(string $column): ?string
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select('pragma table_info(shipments)');
            foreach ($rows as $info) {
                if (($info->name ?? null) === $column) {
                    return (string) ($info->type ?? '');
                }
            }

            return null;
        }

        $database = $connection->getDatabaseName();
        $row = DB::selectOne(
            'select column_type as type from information_schema.columns where table_schema = ? and table_name = ? and column_name = ? limit 1',
            [$database, 'shipments', $column],
        );

        return $row?->type ?? null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertMinimalShipmentRow(array $overrides = []): int
    {
        $sourceId = 'MIG-'.uniqid();
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
        ]);

        $shipment = Shipment::query()->create(array_merge([
            'shipment_no' => 'HW-'.$sourceId,
            'commerce_order_id' => $commerce->id,
            'provider' => 'test',
            'status' => ShipmentStatus::AwbAssigned,
            'external_order_id' => 'ext-order-'.$sourceId,
            'external_shipment_id' => 'ext-ship-'.$sourceId,
            'awb' => 'AWB'.random_int(100000, 999999),
            'idempotency_key' => 'hardware:shiprocket:create:'.$sourceId,
            'correlation_id' => (string) Str::uuid(),
        ], $overrides));

        return (int) $shipment->id;
    }
}
