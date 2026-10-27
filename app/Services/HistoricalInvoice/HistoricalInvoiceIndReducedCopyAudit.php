<?php

namespace App\Services\HistoricalInvoice;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

final class HistoricalInvoiceIndReducedCopyAudit
{
    public const EVENT_VIEWED = 'finance.historical_ind_reduced_copy.viewed';

    public const EVENT_PRINTED = 'finance.historical_ind_reduced_copy.printed';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordView(User $actor, HistoricalInvoiceResult $result, Request $request): void
    {
        $this->record($actor, self::EVENT_VIEWED, $result, $request);
    }

    public function recordPrint(User $actor, HistoricalInvoiceResult $result, Request $request): void
    {
        $this->record($actor, self::EVENT_PRINTED, $result, $request);
    }

    private function record(User $actor, string $event, HistoricalInvoiceResult $result, Request $request): void
    {
        if (! $result->isReducedIndCopy()) {
            return;
        }

        $this->auditLogService->log(
            $actor->id,
            $event,
            $actor,
            null,
            [
                'order_id' => $result->orderId,
                'invoice_number' => $result->invoiceNumber,
                'source' => $result->source,
            ],
            $request,
        );
    }
}
