<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\EInvoiceCancelOutcome;

final class EInvoiceCancelResult
{
    public function __construct(
        public readonly string $provider,
        public readonly EInvoiceCancelOutcome $outcome,
        public readonly ?string $irn = null,
        public readonly mixed $payload = null,
        public readonly ?string $correlationId = null,
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->outcome, [
            EInvoiceCancelOutcome::NotRequired,
            EInvoiceCancelOutcome::AlreadyCancelled,
            EInvoiceCancelOutcome::Success,
        ], true);
    }

    public static function notRequired(string $provider, mixed $payload = null): self
    {
        return new self(
            provider: $provider,
            outcome: EInvoiceCancelOutcome::NotRequired,
            payload: $payload,
        );
    }

    public static function alreadyCancelled(string $provider, ?string $irn = null, mixed $payload = null): self
    {
        return new self(
            provider: $provider,
            outcome: EInvoiceCancelOutcome::AlreadyCancelled,
            irn: $irn,
            payload: $payload,
        );
    }

    public static function success(string $provider, ?string $irn = null, mixed $payload = null, ?string $correlationId = null): self
    {
        return new self(
            provider: $provider,
            outcome: EInvoiceCancelOutcome::Success,
            irn: $irn,
            payload: $payload,
            correlationId: $correlationId,
        );
    }

    public static function temporaryFailure(string $provider, mixed $payload = null, ?string $correlationId = null): self
    {
        return new self(
            provider: $provider,
            outcome: EInvoiceCancelOutcome::TemporaryFailure,
            payload: $payload,
            correlationId: $correlationId,
        );
    }

    public static function permanentFailure(string $provider, mixed $payload = null, ?string $correlationId = null): self
    {
        return new self(
            provider: $provider,
            outcome: EInvoiceCancelOutcome::PermanentFailure,
            payload: $payload,
            correlationId: $correlationId,
        );
    }

    public static function providerNotImplemented(string $provider, mixed $payload = null): self
    {
        return new self(
            provider: $provider,
            outcome: EInvoiceCancelOutcome::ProviderNotImplemented,
            payload: $payload,
        );
    }
}
