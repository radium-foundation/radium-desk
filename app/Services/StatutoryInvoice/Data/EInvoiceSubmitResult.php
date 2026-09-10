<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\EInvoiceSubmitOutcome;

final class EInvoiceSubmitResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $irn = null,
        public readonly ?string $ackNo = null,
        public readonly mixed $payload = null,
        public readonly ?string $ackDate = null,
        public readonly ?string $signedQr = null,
        public readonly ?string $correlationId = null,
        public readonly EInvoiceSubmitOutcome $outcome = EInvoiceSubmitOutcome::Skipped,
    ) {}

    public static function skipped(string $provider, mixed $payload = null): self
    {
        return new self(
            provider: $provider,
            status: EInvoiceSubmitOutcome::Skipped->value,
            payload: $payload,
            outcome: EInvoiceSubmitOutcome::Skipped,
        );
    }

    public static function success(
        string $provider,
        string $irn,
        ?string $ackNo = null,
        ?string $ackDate = null,
        ?string $signedQr = null,
        ?string $correlationId = null,
        mixed $payload = null,
    ): self {
        return new self(
            provider: $provider,
            status: EInvoiceSubmitOutcome::Success->value,
            irn: $irn,
            ackNo: $ackNo,
            payload: $payload,
            ackDate: $ackDate,
            signedQr: $signedQr,
            correlationId: $correlationId,
            outcome: EInvoiceSubmitOutcome::Success,
        );
    }

    public static function temporaryFailure(string $provider, mixed $payload = null, ?string $correlationId = null): self
    {
        return new self(
            provider: $provider,
            status: EInvoiceSubmitOutcome::TemporaryFailure->value,
            payload: $payload,
            correlationId: $correlationId,
            outcome: EInvoiceSubmitOutcome::TemporaryFailure,
        );
    }

    public static function permanentFailure(string $provider, mixed $payload = null, ?string $correlationId = null): self
    {
        return new self(
            provider: $provider,
            status: EInvoiceSubmitOutcome::PermanentFailure->value,
            payload: $payload,
            correlationId: $correlationId,
            outcome: EInvoiceSubmitOutcome::PermanentFailure,
        );
    }

    public static function ambiguous(string $provider, mixed $payload = null, ?string $correlationId = null): self
    {
        return new self(
            provider: $provider,
            status: EInvoiceSubmitOutcome::Ambiguous->value,
            payload: $payload,
            correlationId: $correlationId,
            outcome: EInvoiceSubmitOutcome::Ambiguous,
        );
    }
}
