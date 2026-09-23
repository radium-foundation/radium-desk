<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owner-authorized single-invoice CGST/SGST correction for INV-2767116 / RD3787.
 * Does not modify e_invoice_records or submit to IRP.
 */
final class Inv2767116CgstSgstSnapshotRemediation
{
    public const SCOPE_START = '2026-09-01 00:00:00';

    public const PROMPT_ID = 'RadiumDesk-P-23-09-21';

    public const TARGET_INVOICE_ID = 2453;

    public const TARGET_INVOICE_NUMBER = 'INV-2767116';

    public const TARGET_SOURCE_ID = 'RD3787';

    /**
     * @return array{generated_at: string, manifest_sha256: string, invoices: list<array<string, mixed>>}
     */
    public function buildManifest(?Carbon $generatedAt = null): array
    {
        $generatedAt ??= now();
        $invoice = $this->eligibleInvoice();
        $proposal = B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice);
        if ($proposal === null) {
            throw new RuntimeException('Non-deterministic allocation for '.self::TARGET_INVOICE_NUMBER);
        }

        $manifest = [
            'prompt_id' => self::PROMPT_ID,
            'algorithm' => 'IntraStateCgstSgstRules@v4.0.127+zero-line-hardening',
            'generated_at' => $generatedAt->toIso8601String(),
            'invoices' => [$this->manifestRow($invoice, $proposal, $generatedAt)],
        ];
        $manifest['manifest_sha256'] = self::hashManifest($manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function validateManifest(array $manifest): array
    {
        $errors = [];
        $invoices = $manifest['invoices'] ?? null;
        if (! is_array($invoices) || count($invoices) !== 1) {
            $errors[] = 'manifest must contain exactly 1 invoice';

            return $errors;
        }

        $expectedHash = self::hashManifest($manifest);
        if (($manifest['manifest_sha256'] ?? '') !== $expectedHash) {
            $errors[] = 'manifest_sha256 mismatch';
        }

        $errors = array_merge($errors, $this->validateManifestRow($invoices[0]));

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function writeDatabaseBackup(array $manifest, string $absolutePath): void
    {
        $invoiceId = self::TARGET_INVOICE_ID;
        $payload = [
            'prompt_id' => self::PROMPT_ID,
            'manifest_sha256' => $manifest['manifest_sha256'] ?? null,
            'generated_at' => now()->toIso8601String(),
            'statutory_invoices' => DB::table('statutory_invoices')->where('id', $invoiceId)->get()->all(),
            'statutory_invoice_items' => DB::table('statutory_invoice_items')->where('invoice_id', $invoiceId)->orderBy('id')->get()->all(),
            'e_invoice_records' => DB::table('e_invoice_records')->where('invoice_id', $invoiceId)->get()->all(),
        ];

        $this->writeJson($absolutePath, $payload);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{applied: int, results: list<array<string, mixed>>}
     */
    public function apply(array $manifest, string $actor): array
    {
        $errors = $this->validateManifest($manifest);
        if ($errors !== []) {
            throw new RuntimeException('Manifest validation failed: '.implode('; ', $errors));
        }

        if (! EInvoiceIrpSubmissionHold::isHeld(self::TARGET_INVOICE_ID)) {
            throw new RuntimeException('IRP hold missing for invoice id '.self::TARGET_INVOICE_ID);
        }

        $row = $manifest['invoices'][0];
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $result = ['invoice_id' => $invoiceId, 'invoice_number' => $row['invoice_number'] ?? '', 'applied' => false];

        DB::transaction(function () use ($row, $invoiceId, &$result): void {
            $invoice = DB::table('statutory_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null) {
                throw new RuntimeException('Invoice not found: '.$invoiceId);
            }

            $this->assertRowMatchesDatabase($invoice, $row);
            $this->assertEInvoiceRecordUnchanged($invoiceId);

            foreach ($row['items'] as $itemRow) {
                $itemId = (int) $itemRow['item_id'];
                $item = DB::table('statutory_invoice_items')
                    ->where('id', $itemId)
                    ->where('invoice_id', $invoiceId)
                    ->lockForUpdate()
                    ->first();
                if ($item === null) {
                    throw new RuntimeException('Line item not found: '.$itemId);
                }

                $this->assertMoneyEqual((string) $item->cgst, (string) $itemRow['old_cgst'], 'line.cgst');
                $this->assertMoneyEqual((string) $item->sgst, (string) $itemRow['old_sgst'], 'line.sgst');

                if ($this->moneyChanged($itemRow['old_cgst'], $itemRow['new_cgst'])
                    || $this->moneyChanged($itemRow['old_sgst'], $itemRow['new_sgst'])) {
                    DB::table('statutory_invoice_items')->where('id', $itemId)->update([
                        'cgst' => $itemRow['new_cgst'],
                        'sgst' => $itemRow['new_sgst'],
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('statutory_invoices')->where('id', $invoiceId)->update([
                'cgst' => $row['new_header_cgst'],
                'sgst' => $row['new_header_sgst'],
                'updated_at' => now(),
            ]);

            $this->assertPostCorrection($invoiceId);
            $this->assertEInvoiceRecordUnchanged($invoiceId);
            $result['applied'] = true;
        });

        Log::info('inv_2767116_cgst_sgst_remediation_applied', [
            'actor' => $actor,
            'manifest_sha256' => $manifest['manifest_sha256'] ?? null,
            'invoice_id' => $invoiceId,
        ]);

        return ['applied' => $result['applied'] ? 1 : 0, 'results' => [$result]];
    }

    public function eligibleInvoice(): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()
            ->with(['items' => fn ($q) => $q->orderBy('line_no'), 'eInvoiceRecord'])
            ->where('id', self::TARGET_INVOICE_ID)
            ->where('invoice_number', self::TARGET_INVOICE_NUMBER)
            ->first();

        if ($invoice === null) {
            throw new RuntimeException('Target invoice not found.');
        }

        if (! $this->isEligible($invoice)) {
            throw new RuntimeException('Invoice '.self::TARGET_INVOICE_NUMBER.' failed eligibility.');
        }

        return $invoice;
    }

    public static function hashManifest(array $manifest): string
    {
        $copy = $manifest;
        unset($copy['manifest_sha256']);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function isEligible(StatutoryInvoice $invoice): bool
    {
        if ((int) $invoice->id !== self::TARGET_INVOICE_ID) {
            return false;
        }

        if ((string) $invoice->invoice_number !== self::TARGET_INVOICE_NUMBER) {
            return false;
        }

        if ((string) $invoice->source_id !== self::TARGET_SOURCE_ID) {
            return false;
        }

        if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
            return false;
        }

        if ($invoice->issued_at === null || $invoice->issued_at->lt(Carbon::parse(self::SCOPE_START))) {
            return false;
        }

        if (IntraStateCgstSgstRules::moneyPaise((float) $invoice->cgst)
            === IntraStateCgstSgstRules::moneyPaise((float) $invoice->sgst)) {
            return false;
        }

        $record = $invoice->eInvoiceRecord;
        if ($record === null || (string) $record->status !== 'skipped') {
            return false;
        }

        if ($record->irn !== null && trim((string) $record->irn) !== '') {
            return false;
        }

        $payload = $record->response_payload;

        return is_array($payload) && ($payload['skip_reason'] ?? null) === 'b2c_not_eligible';
    }

    /**
     * @param  array{header_cgst: float, header_sgst: float, line_halves: list<int>}  $proposal
     * @return array<string, mixed>
     */
    private function manifestRow(StatutoryInvoice $invoice, array $proposal, Carbon $generatedAt): array
    {
        $items = [];
        foreach ($invoice->items as $index => $item) {
            $half = IntraStateCgstSgstRules::fromPaise($proposal['line_halves'][$index]);
            $items[] = [
                'item_id' => (int) $item->id,
                'line_no' => (int) $item->line_no,
                'gst_percentage' => (string) $item->gst_percentage,
                'taxable_value' => (string) $item->taxable_value,
                'tax_total' => (string) $item->tax_total,
                'old_cgst' => (string) $item->cgst,
                'old_sgst' => (string) $item->sgst,
                'new_cgst' => number_format($half, 2, '.', ''),
                'new_sgst' => number_format($half, 2, '.', ''),
            ];
        }

        return [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'source_id' => (string) $invoice->source_id,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'old_header_cgst' => (string) $invoice->cgst,
            'old_header_sgst' => (string) $invoice->sgst,
            'new_header_cgst' => number_format($proposal['header_cgst'], 2, '.', ''),
            'new_header_sgst' => number_format($proposal['header_sgst'], 2, '.', ''),
            'tax_total' => (string) $invoice->tax_total,
            'invoice_value' => (string) $invoice->invoice_value,
            'taxable_value' => (string) $invoice->taxable_value,
            'items' => $items,
            'manifest_generated_at' => $generatedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validateManifestRow(array $row): array
    {
        $errors = [];
        $invoiceId = (int) ($row['invoice_id'] ?? 0);

        if ($invoiceId !== self::TARGET_INVOICE_ID) {
            $errors[] = 'invoice_id must be '.self::TARGET_INVOICE_ID;
        }

        if (($row['invoice_number'] ?? '') !== self::TARGET_INVOICE_NUMBER) {
            $errors[] = 'invoice_number must be '.self::TARGET_INVOICE_NUMBER;
        }

        if (($row['source_id'] ?? '') !== self::TARGET_SOURCE_ID) {
            $errors[] = 'source_id must be '.self::TARGET_SOURCE_ID;
        }

        $invoice = StatutoryInvoice::query()->with(['items', 'eInvoiceRecord'])->find($invoiceId);
        if ($invoice === null) {
            $errors[] = self::TARGET_INVOICE_NUMBER.': not found';

            return $errors;
        }

        if (! $this->isEligible($invoice)) {
            $errors[] = self::TARGET_INVOICE_NUMBER.': not eligible';
        }

        $this->assertRowMatchesDatabase($invoice, $row);

        $proposal = B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice);
        if ($proposal === null) {
            $errors[] = self::TARGET_INVOICE_NUMBER.': non-deterministic proposal';

            return $errors;
        }

        if (! $this->moneyEqual($row['new_header_cgst'], $proposal['header_cgst'])) {
            $errors[] = self::TARGET_INVOICE_NUMBER.': manifest header does not match algorithm';
        }

        foreach ($row['items'] as $index => $itemRow) {
            $expected = IntraStateCgstSgstRules::fromPaise($proposal['line_halves'][$index]);
            if (! $this->moneyEqual($itemRow['new_cgst'], $expected) || $itemRow['new_cgst'] !== $itemRow['new_sgst']) {
                $errors[] = self::TARGET_INVOICE_NUMBER.': line '.$itemRow['line_no'].' manifest mismatch';
            }
        }

        return $errors;
    }

    /**
     * @param  object|StatutoryInvoice  $invoice
     * @param  array<string, mixed>  $row
     */
    private function assertRowMatchesDatabase(object $invoice, array $row): void
    {
        $this->assertMoneyEqual((string) $invoice->cgst, (string) $row['old_header_cgst'], 'header.cgst');
        $this->assertMoneyEqual((string) $invoice->sgst, (string) $row['old_header_sgst'], 'header.sgst');
        $this->assertMoneyEqual((string) $invoice->tax_total, (string) $row['tax_total'], 'tax_total');
        $this->assertMoneyEqual((string) $invoice->invoice_value, (string) $row['invoice_value'], 'invoice_value');
        $this->assertMoneyEqual((string) $invoice->taxable_value, (string) $row['taxable_value'], 'taxable_value');

        $itemsById = [];
        if ($invoice instanceof StatutoryInvoice) {
            foreach ($invoice->items as $item) {
                $itemsById[(int) $item->id] = $item;
            }
        } else {
            foreach (DB::table('statutory_invoice_items')->where('invoice_id', (int) $invoice->id)->get() as $item) {
                $itemsById[(int) $item->id] = $item;
            }
        }

        foreach ($row['items'] as $itemRow) {
            $item = $itemsById[(int) $itemRow['item_id']] ?? null;
            if ($item === null) {
                throw new RuntimeException('Manifest item '.$itemRow['item_id'].' missing.');
            }
            $this->assertMoneyEqual((string) $item->cgst, (string) $itemRow['old_cgst'], 'item.cgst');
            $this->assertMoneyEqual((string) $item->sgst, (string) $itemRow['old_sgst'], 'item.sgst');
        }
    }

    private function assertEInvoiceRecordUnchanged(int $invoiceId): void
    {
        $record = DB::table('e_invoice_records')->where('invoice_id', $invoiceId)->first();
        if ($record === null || (string) $record->status !== 'skipped') {
            throw new RuntimeException('e_invoice_record changed for invoice '.$invoiceId);
        }

        if ($record->irn !== null && trim((string) $record->irn) !== '') {
            throw new RuntimeException('e_invoice_record IRN present for invoice '.$invoiceId);
        }
    }

    private function assertPostCorrection(int $invoiceId): void
    {
        $invoice = StatutoryInvoice::query()->with('items')->findOrFail($invoiceId);
        $reasons = IntraStateCgstSgstRules::storedInvoiceReasons($invoice);
        if ($reasons !== []) {
            throw new RuntimeException(
                'Post-correction guard failed: '.implode(', ', $reasons),
            );
        }
    }

    private function assertMoneyEqual(string $actual, string $expected, string $field): void
    {
        if (! $this->moneyEqual($actual, $expected)) {
            throw new RuntimeException($field.' mismatch: db='.$actual.' manifest='.$expected);
        }
    }

    private function moneyEqual(string|float $a, string|float $b): bool
    {
        return IntraStateCgstSgstRules::moneyPaise((float) $a) === IntraStateCgstSgstRules::moneyPaise((float) $b);
    }

    private function moneyChanged(string|float $old, string|float $new): bool
    {
        return ! $this->moneyEqual($old, $new);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $absolutePath, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode JSON for '.$absolutePath);
        }

        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Failed to create directory: '.$directory);
        }

        if (file_put_contents($absolutePath, $encoded) === false) {
            throw new RuntimeException('Failed to write: '.$absolutePath);
        }
    }
}
