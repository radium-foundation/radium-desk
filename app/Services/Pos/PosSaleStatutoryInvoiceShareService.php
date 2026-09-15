<?php

namespace App\Services\Pos;

use App\Mail\StatutoryInvoiceMail;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Notifications\NotificationMailSender;
use App\Services\StatutoryInvoice\StatutoryInvoiceShareService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Share statutory invoices from walk-in POS sales without a service-case incident.
 */
final class PosSaleStatutoryInvoiceShareService
{
    public function __construct(
        private readonly StatutoryInvoiceShareService $share,
        private readonly NotificationMailSender $mailSender,
    ) {}

    public function pdfBinary(StatutoryInvoice $invoice): string
    {
        return $this->share->pdfBinary($invoice);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function email(InventorySale $sale, StatutoryInvoice $invoice, User $actor, ?string $recipientOverride = null): array
    {
        $sale->loadMissing('customer');
        $recipient = is_string($recipientOverride) && trim($recipientOverride) !== ''
            ? trim($recipientOverride)
            : (is_string($sale->customer?->email) ? trim($sale->customer->email) : '');
        if ($recipient === '') {
            throw ValidationException::withMessages([
                'email' => 'Customer email address is not available.',
            ]);
        }

        if (! $this->mailSender->isEnabled()) {
            throw ValidationException::withMessages([
                'email' => 'Email delivery is disabled.',
            ]);
        }

        $binary = $this->share->pdfBinary($invoice);
        $result = $this->mailSender->send(
            $recipient,
            new StatutoryInvoiceMail(
                (string) $invoice->invoice_number,
                (string) ($invoice->buyer_name ?: $sale->customer?->name ?: 'Customer'),
                $binary,
            ),
        );

        if (! $result['success']) {
            Log::warning('pos_statutory_invoice.email.failed', [
                'sale_id' => $sale->id,
                'invoice_id' => $invoice->id,
                'actor_id' => $actor->id,
            ]);

            throw ValidationException::withMessages([
                'email' => 'The invoice email could not be sent.',
            ]);
        }

        Log::info('pos_statutory_invoice.email.sent', [
            'sale_id' => $sale->id,
            'invoice_id' => $invoice->id,
            'actor_id' => $actor->id,
        ]);

        return [
            'success' => true,
            'message' => 'Invoice emailed to the customer.',
        ];
    }
}
