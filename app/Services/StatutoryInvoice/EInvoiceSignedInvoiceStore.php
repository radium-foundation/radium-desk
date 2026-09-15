<?php

namespace App\Services\StatutoryInvoice;

use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Private-disk persistence for WhiteBooks SignedInvoice.
 * Stores the exact returned string. Does not log or publicly serve contents.
 */
final class EInvoiceSignedInvoiceStore
{
    public const DISK = 'local';

    public const PATH_PREFIX = 'statutory-einvoice';

    public function __construct(private readonly ?string $disk = null) {}

    public function persistFromResult(StatutoryInvoice $invoice, EInvoiceSubmitResult $result): bool
    {
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        if ($record === null) {
            return false;
        }

        return $this->persist($record, $result->signedInvoice);
    }

    public function persist(EInvoiceRecord $record, ?string $signedInvoice): bool
    {
        $payload = $this->normalized($signedInvoice);
        if ($payload === null) {
            $this->syncHasSignedInvoiceFlag($record, $record->hasPersistedSignedInvoice());

            return $record->hasPersistedSignedInvoice();
        }

        if ($record->hasPersistedSignedInvoice()) {
            return true;
        }

        $path = self::PATH_PREFIX.'/'.$record->invoice_id.'/signed-invoice.txt';
        try {
            $written = $this->disk()->put($path, $payload);
        } catch (Throwable $exception) {
            Log::warning('e-invoice signed invoice persist failed', [
                'invoice_id' => $record->invoice_id,
                'bytes' => strlen($payload),
                'error' => $exception::class,
            ]);
            $this->syncHasSignedInvoiceFlag($record, false, persistFailed: true);

            return false;
        }

        if ($written !== true) {
            Log::warning('e-invoice signed invoice persist failed', [
                'invoice_id' => $record->invoice_id,
                'bytes' => strlen($payload),
            ]);
            $this->syncHasSignedInvoiceFlag($record, false, persistFailed: true);

            return false;
        }

        $this->tightenPermissions($path);
        $record->forceFill([
            'signed_invoice_disk' => $this->disk ?? self::DISK,
            'signed_invoice_path' => $path,
            'signed_invoice_sha256' => hash('sha256', $payload),
            'signed_invoice_bytes' => strlen($payload),
            'signed_invoice_persisted_at' => now(),
        ]);
        $this->syncHasSignedInvoiceFlag($record, true);

        return true;
    }

    public function read(EInvoiceRecord $record): ?string
    {
        if (! $record->hasPersistedSignedInvoice()) {
            return null;
        }

        try {
            $raw = $this->diskFor($record)->get((string) $record->signed_invoice_path);
        } catch (Throwable) {
            return null;
        }

        return $this->normalized(is_string($raw) ? $raw : null);
    }

    public function publicUrl(EInvoiceRecord $record): ?string
    {
        unset($record);

        return null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk ?? self::DISK);
    }

    private function diskFor(EInvoiceRecord $record): Filesystem
    {
        $name = is_string($record->signed_invoice_disk) && trim($record->signed_invoice_disk) !== ''
            ? trim($record->signed_invoice_disk)
            : self::DISK;

        return Storage::disk($name);
    }

    private function normalized(?string $signedInvoice): ?string
    {
        if (! is_string($signedInvoice)) {
            return null;
        }

        $trimmed = trim($signedInvoice);

        return $trimmed === '' ? null : $signedInvoice;
    }

    private function syncHasSignedInvoiceFlag(EInvoiceRecord $record, bool $has, bool $persistFailed = false): void
    {
        $payload = is_array($record->response_payload) ? $record->response_payload : [];
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        unset($inner['SignedInvoice']);
        $inner['has_signed_invoice'] = $has;
        if ($persistFailed) {
            $inner['signed_invoice_persist_failed'] = true;
        } else {
            unset($inner['signed_invoice_persist_failed']);
        }
        $payload['payload'] = $inner;
        $record->response_payload = $payload;
        $record->save();
    }

    private function tightenPermissions(string $path): void
    {
        try {
            $full = $this->disk()->path($path);
        } catch (Throwable) {
            return;
        }

        $dir = dirname($full);
        if (is_dir($dir)) {
            @chmod($dir, 0700);
        }
        if (is_file($full)) {
            @chmod($full, 0600);
        }
    }
}
