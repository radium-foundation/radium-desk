<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Mail\StatutoryInvoiceMail;
use App\Models\Incident;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Interakt\WhatsAppTemplateDispatcher;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\Notifications\NotificationCustomerContactResolver;
use App\Services\Notifications\NotificationMailSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StatutoryInvoiceShareService
{
    public function __construct(
        private readonly StatutoryDocumentService $documents,
        private readonly NotificationMailSender $mailSender,
        private readonly NotificationCustomerContactResolver $contacts,
        private readonly NotificationChannelAvailabilityService $channels,
        private readonly WhatsAppTemplateDispatcher $whatsApp,
    ) {}

    public function pdfBinary(StatutoryInvoice $invoice): string
    {
        $invoice->loadMissing('document');
        $document = $invoice->document;
        if ($document === null || $document->status !== StatutoryInvoiceDocumentStatus::Generated) {
            $document = $this->documents->generate($invoice);
        }

        if ($document->status !== StatutoryInvoiceDocumentStatus::Generated) {
            throw ValidationException::withMessages([
                'invoice' => 'The invoice PDF is not available.',
            ]);
        }

        return $this->documents->binary($document);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function email(Incident $incident, StatutoryInvoice $invoice, User $actor): array
    {
        $incident->loadMissing('order');
        $recipient = $this->contacts->resolveEmail($incident->order);
        if ($recipient === null) {
            throw ValidationException::withMessages([
                'email' => 'Customer email address is not available.',
            ]);
        }

        if (! $this->mailSender->isEnabled()) {
            throw ValidationException::withMessages([
                'email' => 'Email delivery is disabled.',
            ]);
        }

        $binary = $this->pdfBinary($invoice);
        $result = $this->mailSender->send(
            $recipient,
            new StatutoryInvoiceMail(
                (string) $invoice->invoice_number,
                (string) ($invoice->buyer_name ?: $incident->order?->customer_name ?: 'Customer'),
                $binary,
            ),
        );

        if (! $result['success']) {
            Log::warning('statutory_invoice.email.failed', [
                'incident_id' => $incident->id,
                'invoice_id' => $invoice->id,
                'actor_id' => $actor->id,
            ]);

            throw ValidationException::withMessages([
                'email' => 'The invoice email could not be sent.',
            ]);
        }

        Log::info('statutory_invoice.email.sent', [
            'incident_id' => $incident->id,
            'invoice_id' => $invoice->id,
            'actor_id' => $actor->id,
        ]);

        return [
            'success' => true,
            'message' => 'Invoice emailed to the customer.',
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function whatsapp(Incident $incident, StatutoryInvoice $invoice, User $actor): array
    {
        $incident->loadMissing('order');
        $availability = $this->channels->assessWhatsApp($incident->order, WhatsAppTemplate::StatutoryInvoice);
        if (! ($availability['available'] ?? false)) {
            throw ValidationException::withMessages([
                'whatsapp' => $availability['reason'] ?? 'WhatsApp invoice sharing is not configured.',
            ]);
        }

        $customerName = trim((string) ($incident->order?->customer_name ?: $invoice->buyer_name ?: 'Customer'));
        $result = $this->whatsApp->dispatch(
            WhatsAppTemplate::StatutoryInvoice,
            $incident,
            $actor,
            WhatsAppTemplateTriggerSource::Manual,
            [
                'body_values' => [$customerName, (string) $invoice->invoice_number],
                'invoice_id' => $invoice->id,
            ],
        );

        if (! $result->success) {
            Log::warning('statutory_invoice.whatsapp.failed', [
                'incident_id' => $incident->id,
                'invoice_id' => $invoice->id,
                'actor_id' => $actor->id,
            ]);

            throw ValidationException::withMessages([
                'whatsapp' => $result->message ?? 'The invoice WhatsApp message could not be sent.',
            ]);
        }

        Log::info('statutory_invoice.whatsapp.sent', [
            'incident_id' => $incident->id,
            'invoice_id' => $invoice->id,
            'actor_id' => $actor->id,
        ]);

        return [
            'success' => true,
            'message' => 'Invoice shared on WhatsApp.',
        ];
    }
}
