<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owner-authorized B2C-only historical CGST/SGST snapshot correction.
 * Uses direct SQL; does not weaken StatutoryInvoice Eloquent immutability.
 */
final class B2cCgstSgstSnapshotRemediation
{
    public const SCOPE_START = '2026-09-01 00:00:00';

    public const UNKNOWN_EXCLUDED_INVOICE_NUMBER = 'INV-2767116';

    /**
     * @var list<string>
     */
    public const B2B_EXCLUDED_INVOICE_NUMBERS = [
        'INV-076775',
        'INV-076780',
        'INV-2767203',
        'INV-2767251',
        'INV-2767279',
        'INV-0767278',
    ];

    /**
     * @return array{generated_at: string, manifest_sha256: string, invoices: list<array<string, mixed>>}
     */
    public function buildManifest(?Carbon $generatedAt = null): array
    {
        $generatedAt ??= now();
        $invoices = $this->eligibleInvoices();
        $rows = [];

        foreach ($invoices as $invoice) {
            $proposal = self::proposeCorrection($invoice);
            if ($proposal === null) {
                throw new RuntimeException(
                    'Non-deterministic allocation for invoice '.$invoice->invoice_number.' (id '.$invoice->id.').',
                );
            }

            $rows[] = $this->manifestRow($invoice, $proposal, $generatedAt);
        }

        if (count($rows) !== 70) {
            throw new RuntimeException('Expected exactly 70 B2C manifest rows, got '.count($rows).'.');
        }

        $manifest = [
            'prompt_id' => 'RadiumDesk-P-23-09-18',
            'algorithm' => 'IntraStateCgstSgstRules@v4.0.127',
            'generated_at' => $generatedAt->toIso8601String(),
            'invoices' => $rows,
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
        if (! is_array($invoices) || count($invoices) !== 70) {
            $errors[] = 'manifest must contain exactly 70 invoices';

            return $errors;
        }

        $expectedHash = self::hashManifest($manifest);
        if (($manifest['manifest_sha256'] ?? '') !== $expectedHash) {
            $errors[] = 'manifest_sha256 mismatch';
        }

        foreach ($invoices as $row) {
            $errors = array_merge($errors, $this->validateManifestRow($row));
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function writeBackup(array $manifest, string $absolutePath): void
    {
        $payload = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Failed to encode backup manifest.');
        }

        $directory = dirname($absolutePath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Failed to create backup directory: '.$directory);
        }

        if (file_put_contents($absolutePath, $payload) === false) {
            throw new RuntimeException('Failed to write backup: '.$absolutePath);
        }
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

        $results = [];
        $applied = 0;

        foreach ($manifest['invoices'] as $row) {
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $result = ['invoice_id' => $invoiceId, 'invoice_number' => $row['invoice_number'] ?? '', 'applied' => false];

            try {
                DB::transaction(function () use ($row, $invoiceId, &$result): void {
                    $invoice = DB::table('statutory_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
                    if ($invoice === null) {
                        throw new RuntimeException('Invoice not found: '.$invoiceId);
                    }

                    $this->assertRowMatchesDatabase($invoice, $row);

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
                    $result['applied'] = true;
                });

                if ($result['applied']) {
                    $applied++;
                }
            } catch (\Throwable $exception) {
                $result['error'] = $exception->getMessage();
                Log::error('b2c_cgst_sgst_remediation_failed', [
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $row['invoice_number'] ?? null,
                    'actor' => $actor,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $results[] = $result;
        }

        Log::info('b2c_cgst_sgst_remediation_applied', [
            'actor' => $actor,
            'manifest_sha256' => $manifest['manifest_sha256'] ?? null,
            'applied' => $applied,
            'invoice_ids' => array_column($results, 'invoice_id'),
        ]);

        return ['applied' => $applied, 'results' => $results];
    }

    /**
     * @return list<StatutoryInvoice>
     */
    public function eligibleInvoices(): array
    {
        $ids = DB::table('statutory_invoices as si')
            ->join('e_invoice_records as eir', 'eir.invoice_id', '=', 'si.id')
            ->where('si.issued_at', '>=', self::SCOPE_START)
            ->where('si.status', StatutoryInvoiceStatus::Issued->value)
            ->whereRaw('COALESCE(si.igst, 0) = 0')
            ->whereRaw('(COALESCE(si.cgst, 0) > 0 OR COALESCE(si.sgst, 0) > 0)')
            ->whereRaw('ROUND(COALESCE(si.cgst, 0) * 100) <> ROUND(COALESCE(si.sgst, 0) * 100)')
            ->where(function ($query): void {
                $query->whereNull('si.buyer_gstin')->orWhereRaw('TRIM(si.buyer_gstin) = ?', ['']);
            })
            ->where('eir.status', 'skipped')
            ->whereNull('eir.irn')
            ->whereNotIn('si.invoice_number', self::B2B_EXCLUDED_INVOICE_NUMBERS)
            ->where('si.invoice_number', '<>', self::UNKNOWN_EXCLUDED_INVOICE_NUMBER)
            ->orderBy('si.id')
            ->pluck('si.id')
            ->all();

        $invoices = StatutoryInvoice::query()
            ->with(['items' => fn ($q) => $q->orderBy('line_no'), 'eInvoiceRecord'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->all();

        foreach ($invoices as $invoice) {
            if (! $this->isEligibleB2cSkipped($invoice)) {
                throw new RuntimeException('Invoice '.$invoice->invoice_number.' failed B2C skipped eligibility.');
            }
        }

        return $invoices;
    }

    /**
     * @return array{header_cgst: float, header_sgst: float, line_halves: list<int>}|null
     */
    public static function proposeCorrection(StatutoryInvoice $invoice): ?array
    {
        $invoice->loadMissing(['items' => fn ($q) => $q->orderBy('line_no')]);
        if ($invoice->items->isEmpty()) {
            return null;
        }

        $taxPaise = IntraStateCgstSgstRules::moneyPaise((float) $invoice->tax_total);
        $idealHalves = [];
        foreach ($invoice->items as $item) {
            $idealHalves[] = IntraStateCgstSgstRules::idealHalfFromTaxablePaise(
                IntraStateCgstSgstRules::moneyPaise((float) $item->taxable_value),
                (float) $item->gst_percentage,
            );
        }

        $headerHalf = IntraStateCgstSgstRules::headerHalfFromTotalTaxPaise($taxPaise);
        $allocated = IntraStateCgstSgstRules::allocateLineHalfPaise($headerHalf, $idealHalves);

        foreach ($allocated as $half) {
            if ($half < 0) {
                return null;
            }
        }

        try {
            IntraStateCgstSgstRules::assertMintSnapshot($taxPaise, $allocated);
        } catch (\Throwable) {
            return null;
        }

        return [
            'header_cgst' => IntraStateCgstSgstRules::fromPaise($headerHalf),
            'header_sgst' => IntraStateCgstSgstRules::fromPaise($headerHalf),
            'line_halves' => $allocated,
        ];
    }

    public static function hashManifest(array $manifest): string
    {
        $copy = $manifest;
        unset($copy['manifest_sha256']);

        return hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function isEligibleB2cSkipped(StatutoryInvoice $invoice): bool
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null || (string) $record->status !== 'skipped') {
            return false;
        }

        if ($record->irn !== null && trim((string) $record->irn) !== '') {
            return false;
        }

        $payload = $record->response_payload;
        if (! is_array($payload)) {
            return false;
        }

        return ($payload['skip_reason'] ?? null) === 'b2c_not_eligible';
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
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'source_id' => (string) $invoice->source_id,
            'source_order_id' => (string) ($invoice->source_order_id ?? ''),
            'old_header_cgst' => (string) $invoice->cgst,
            'old_header_sgst' => (string) $invoice->sgst,
            'new_header_cgst' => number_format($proposal['header_cgst'], 2, '.', ''),
            'new_header_sgst' => number_format($proposal['header_sgst'], 2, '.', ''),
            'tax_total' => (string) $invoice->tax_total,
            'invoice_value' => (string) $invoice->invoice_value,
            'taxable_value' => (string) $invoice->taxable_value,
            'igst' => (string) $invoice->igst,
            'shipping_amount' => (string) $invoice->shipping_amount,
            'buyer_gstin' => $invoice->buyer_gstin,
            'place_of_supply_state' => (string) $invoice->place_of_supply_state,
            'e_invoice_status' => (string) $invoice->eInvoiceRecord?->status,
            'skip_reason' => is_array($invoice->eInvoiceRecord?->response_payload)
                ? ($invoice->eInvoiceRecord->response_payload['skip_reason'] ?? null)
                : null,
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
        $invoiceNumber = (string) ($row['invoice_number'] ?? '');
        $invoiceId = (int) ($row['invoice_id'] ?? 0);

        if (in_array($invoiceNumber, self::B2B_EXCLUDED_INVOICE_NUMBERS, true)) {
            $errors[] = $invoiceNumber.': B2B excluded';
        }
        if ($invoiceNumber === self::UNKNOWN_EXCLUDED_INVOICE_NUMBER) {
            $errors[] = $invoiceNumber.': UNKNOWN excluded';
        }

        if ($row['new_header_cgst'] !== $row['new_header_sgst']) {
            $errors[] = $invoiceNumber.': header CGST/SGST unequal in manifest';
        }

        $invoice = StatutoryInvoice::query()->with(['items', 'eInvoiceRecord'])->find($invoiceId);
        if ($invoice === null) {
            $errors[] = $invoiceNumber.': not found';

            return $errors;
        }

        if ($invoice->issued_at === null || $invoice->issued_at->lt(Carbon::parse(self::SCOPE_START))) {
            $errors[] = $invoiceNumber.': before scope';
        }

        if (! $this->isEligibleB2cSkipped($invoice)) {
            $errors[] = $invoiceNumber.': not B2C skipped';
        }

        $this->assertRowMatchesDatabase($invoice, $row);

        $proposal = self::proposeCorrection($invoice);
        if ($proposal === null) {
            $errors[] = $invoiceNumber.': non-deterministic proposal';

            return $errors;
        }

        if (! $this->moneyEqual($row['new_header_cgst'], $proposal['header_cgst'])) {
            $errors[] = $invoiceNumber.': manifest header does not match algorithm';
        }

        foreach ($row['items'] as $index => $itemRow) {
            $expected = IntraStateCgstSgstRules::fromPaise($proposal['line_halves'][$index]);
            if (! $this->moneyEqual($itemRow['new_cgst'], $expected) || $itemRow['new_cgst'] !== $itemRow['new_sgst']) {
                $errors[] = $invoiceNumber.': line '.$itemRow['line_no'].' manifest mismatch';
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
            $dbItems = DB::table('statutory_invoice_items')->where('invoice_id', (int) $invoice->id)->get();
            foreach ($dbItems as $item) {
                $itemsById[(int) $item->id] = $item;
            }
        }

        foreach ($row['items'] as $itemRow) {
            $item = $itemsById[(int) $itemRow['item_id']] ?? null;
            if ($item === null) {
                throw new RuntimeException('Manifest item '.$itemRow['item_id'].' missing on invoice '.$row['invoice_number']);
            }
            $this->assertMoneyEqual((string) $item->cgst, (string) $itemRow['old_cgst'], 'item.cgst');
            $this->assertMoneyEqual((string) $item->sgst, (string) $itemRow['old_sgst'], 'item.sgst');
            $this->assertMoneyEqual((string) $item->gst_percentage, (string) $itemRow['gst_percentage'], 'item.gst_percentage');
            $this->assertMoneyEqual((string) $item->taxable_value, (string) $itemRow['taxable_value'], 'item.taxable_value');
            $this->assertMoneyEqual((string) $item->tax_total, (string) $itemRow['tax_total'], 'item.tax_total');
        }
    }

    private function assertPostCorrection(int $invoiceId): void
    {
        $invoice = StatutoryInvoice::query()->with('items')->findOrFail($invoiceId);
        $reasons = IntraStateCgstSgstRules::storedInvoiceReasons($invoice);
        if ($reasons !== []) {
            throw new RuntimeException(
                'Post-correction guard failed for '.$invoice->invoice_number.': '.implode(', ', $reasons),
            );
        }

        if (IntraStateCgstSgstRules::moneyPaise((float) $invoice->cgst)
            !== IntraStateCgstSgstRules::moneyPaise((float) $invoice->sgst)) {
            throw new RuntimeException('Post-correction header unequal for '.$invoice->invoice_number);
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
}
