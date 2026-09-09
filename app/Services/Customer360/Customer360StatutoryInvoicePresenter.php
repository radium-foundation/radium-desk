<?php

namespace App\Services\Customer360;

use App\Enums\StatutoryInvoiceStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\Incident;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\Notifications\NotificationCustomerContactResolver;
use App\Services\Notifications\NotificationMailSender;
use App\Services\StatutoryInvoice\StatutoryInvoiceForIncidentResolver;
use App\Support\AppDateFormatter;

class Customer360StatutoryInvoicePresenter
{
    public function __construct(
        private readonly StatutoryInvoiceForIncidentResolver $resolver,
        private readonly NotificationCustomerContactResolver $contacts,
        private readonly NotificationMailSender $mailSender,
        private readonly NotificationChannelAvailabilityService $channels,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forIncident(Incident $incident, ?User $user): array
    {
        if ($user === null || $user->cannot('view', $incident)) {
            return [];
        }

        $incident->loadMissing('order');

        return $this->resolver
            ->forIncident($incident)
            ->map(fn (StatutoryInvoice $invoice): array => $this->present($incident, $invoice))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Incident $incident, StatutoryInvoice $invoice): array
    {
        $email = $this->contacts->resolveEmail($incident->order);
        $whatsapp = $this->channels->assessWhatsApp($incident->order, WhatsAppTemplate::StatutoryInvoice);
        $canEmail = $email !== null && $this->mailSender->isEnabled();
        $canWhatsapp = (bool) ($whatsapp['available'] ?? false);

        return [
            'id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'issued_at_label' => AppDateFormatter::date($invoice->issued_at),
            'invoice_value' => number_format((float) $invoice->invoice_value, 2, '.', ''),
            'status_label' => $invoice->status instanceof StatutoryInvoiceStatus
                ? $invoice->status->label()
                : 'Issued',
            'view_url' => route('dashboard.service-cases.customer-360.invoices.pdf', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]),
            'download_url' => route('dashboard.service-cases.customer-360.invoices.download', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]),
            'email_url' => route('dashboard.service-cases.customer-360.invoices.email', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]),
            'whatsapp_url' => route('dashboard.service-cases.customer-360.invoices.whatsapp', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]),
            'can_email' => $canEmail,
            'can_whatsapp' => $canWhatsapp,
            'email_unavailable_reason' => $canEmail
                ? null
                : ($email === null
                    ? 'Customer email is not on file.'
                    : 'Email delivery is disabled.'),
            'whatsapp_unavailable_reason' => $canWhatsapp
                ? null
                : ($whatsapp['reason'] ?? 'WhatsApp invoice sharing is not configured.'),
        ];
    }
}
