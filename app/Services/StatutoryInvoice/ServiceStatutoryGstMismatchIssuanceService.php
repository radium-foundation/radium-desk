<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoice\ServiceStatutoryGstMismatchStatus;
use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Models\CommerceOrder;
use App\Models\ServiceStatutoryGstMismatchException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ServiceStatutoryGstMismatchIssuanceService
{
    public function __construct(
        private readonly ServiceStatutoryGstMismatchExceptionService $exceptions,
        private readonly ServiceStatutoryInvoiceIssuanceCoordinator $coordinator,
    ) {}

    public function issueB2bIfReady(ServiceStatutoryGstMismatchException $exception, ?int $actorId = null): void
    {
        if ($exception->status !== ServiceStatutoryGstMismatchStatus::ReadyForB2b) {
            return;
        }

        DB::transaction(function () use ($exception, $actorId): void {
            $locked = ServiceStatutoryGstMismatchException::query()
                ->whereKey($exception->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ServiceStatutoryGstMismatchStatus::ReadyForB2b) {
                return;
            }

            $supportOrder = $locked->supportOrder;
            if ($supportOrder === null) {
                return;
            }

            $commerce = CommerceOrder::query()->whereKey($locked->commerce_order_id)->lockForUpdate()->firstOrFail();
            if ($commerce->statutory_invoice_id !== null) {
                $this->exceptions->markB2bIssued($locked, (int) $commerce->statutory_invoice_id);

                return;
            }

            $actor = $actorId !== null ? User::query()->find($actorId) : null;

            $this->coordinator->attempt(
                $supportOrder,
                $actor,
                ServiceStatutoryInvoiceMintTrigger::ManualFinance,
            );

            $commerce->refresh();
            if ($commerce->statutory_invoice_id !== null) {
                $this->exceptions->markB2bIssued($locked, (int) $commerce->statutory_invoice_id);
            }
        });
    }
}
