<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\CarrierDocumentUrlValidator;
use App\Services\HardwareFulfilment\HardwareCarrierDocumentFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HardwareCarrierDocumentFetcherTest extends TestCase
{
    public function test_rejects_oversized_remote_pdf(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response(str_repeat('a', 500), 200),
        ]);

        $fetcher = new HardwareCarrierDocumentFetcher(
            new CarrierDocumentUrlValidator(['apiv2.shiprocket.in']),
            maxBytes: 100,
            timeoutSeconds: 5,
        );

        $this->assertNull($fetcher->fetchRemotePdf('https://apiv2.shiprocket.in/v1/labels/big.pdf'));
    }

    public function test_rejects_failed_http_status(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('nope', 503),
        ]);

        $fetcher = new HardwareCarrierDocumentFetcher(
            new CarrierDocumentUrlValidator(['apiv2.shiprocket.in']),
            maxBytes: 1024,
            timeoutSeconds: 5,
        );

        $this->assertNull($fetcher->fetchRemotePdf('https://apiv2.shiprocket.in/v1/labels/missing.pdf'));
    }

    public function test_reads_local_disk_uploads_only_from_allowed_disks(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        Storage::disk('local')->put('carrier/label.pdf', '%PDF-local%');
        Storage::disk('s3')->put('carrier/evil.pdf', '%PDF%');

        $fetcher = new HardwareCarrierDocumentFetcher(
            new CarrierDocumentUrlValidator(['apiv2.shiprocket.in']),
            maxBytes: 1024 * 1024,
            timeoutSeconds: 5,
        );

        $local = $fetcher->readLocalDisk('local', 'carrier/label.pdf');
        $this->assertNotNull($local);
        $this->assertSame('%PDF-local%', $local['bytes']);

        $this->assertNull($fetcher->readLocalDisk('s3', 'carrier/evil.pdf'));
    }
}
