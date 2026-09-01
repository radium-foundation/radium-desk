<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSequenceAllocation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StatutoryInvoiceNumberingService
{
    public const ATTEMPTS = 5;

    public function __construct(
        private readonly StatutoryInvoiceNumberFormatter $formatter,
    ) {}

    public function isConfigured(): bool
    {
        return $this->seriesCode() !== null && $this->numberFormat() !== null;
    }

    public function findByIdempotency(string $idempotencyKey): ?InvoiceSequenceAllocation
    {
        return InvoiceSequenceAllocation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function allocate(string $idempotencyKey, ?User $actor = null): InvoiceSequenceAllocation
    {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'series' => 'Statutory invoice numbering is unset. CA approval of the legal series is required before invoices can be minted.',
            ]);
        }

        $existing = $this->findByIdempotency($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $sequence = $this->ensureSequenceRow();

        try {
            return DB::transaction(function () use ($idempotencyKey, $actor, $sequence): InvoiceSequenceAllocation {
                $again = $this->findByIdempotency($idempotencyKey);
                if ($again !== null) {
                    return $again;
                }

                $locked = InvoiceSequence::query()
                    ->whereKey($sequence->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $next = $locked->current_value + 1;
                $locked->update(['current_value' => $next]);

                $number = $this->formatter->format(
                    template: $this->numberFormat() ?? '',
                    series: $this->seriesCode() ?? '',
                    seq: $next,
                    gstin: $this->nullableConfig('gstin_scope'),
                    financialYear: $this->nullableConfig('financial_year'),
                );

                $this->assertNotPosInternalReceiptFormat($number);

                return InvoiceSequenceAllocation::query()->create([
                    'sequence_id' => $locked->id,
                    'allocated_number' => $number,
                    'seq_int' => $next,
                    'invoice_id' => null,
                    'idempotency_key' => $idempotencyKey,
                    'allocated_by' => $actor?->id,
                    'allocated_at' => now(),
                ]);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByIdempotency($idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function ensureSequenceRow(): InvoiceSequence
    {
        $key = $this->sequenceKey();
        $existing = InvoiceSequence::query()->where('sequence_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return InvoiceSequence::query()->create([
                'sequence_key' => $key,
                'series_code' => $this->seriesCode(),
                'document_type' => $this->documentType()->value,
                'gstin_scope' => $this->nullableConfig('gstin_scope'),
                'financial_year' => $this->nullableConfig('financial_year'),
                'current_value' => 0,
            ]);
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            $existing = InvoiceSequence::query()->where('sequence_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function sequenceKey(): string
    {
        $gstin = $this->nullableConfig('gstin_scope') ?? '*';
        $fy = $this->nullableConfig('financial_year') ?? '*';

        return implode('|', [
            $this->documentType()->value,
            $this->seriesCode() ?? '',
            $gstin,
            $fy,
        ]);
    }

    private function seriesCode(): ?string
    {
        return $this->nullableConfig('series_code');
    }

    private function numberFormat(): ?string
    {
        return $this->nullableConfig('number_format');
    }

    private function documentType(): StatutoryInvoiceDocumentType
    {
        $raw = (string) config('statutory_invoices.document_type', StatutoryInvoiceDocumentType::TaxInvoice->value);

        return StatutoryInvoiceDocumentType::tryFrom($raw) ?? StatutoryInvoiceDocumentType::TaxInvoice;
    }

    private function nullableConfig(string $key): ?string
    {
        $value = config('statutory_invoices.'.$key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function assertNotPosInternalReceiptFormat(string $number): void
    {
        if (preg_match('/^INV-[A-Z0-9]+-\d{4}-\d{5}$/', $number) === 1) {
            throw ValidationException::withMessages([
                'number_format' => 'Statutory invoice numbers cannot use the internal POS receipt pattern INV-{branch}-{year}-{seq}.',
            ]);
        }
    }
}
