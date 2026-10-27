<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Models\StatutoryInvoiceCancellation;

final readonly class StatutoryInvoiceCancellationAdminContext
{
    public function __construct(
        public bool $maySubmitCancellationRequest,
        public bool $showCancellationForm,
        public bool $showPolicyPanel,
        public bool $requiresFinanceCaEscalation,
        public string $policySummary,
        public string $irnStatusSummary,
        public string $windowSummary,
        public ?StatutoryInvoiceCancellation $operation,
        public ?StatutoryInvoiceCancellationOperationStatus $operationStatus,
        public string $operationStatusLabel,
        public string $operationStatusHelp,
        public ?string $correlationId,
        public ?string $lastError,
        public array $consequences,
        public string $financeCaProcedure,
    ) {}
}
