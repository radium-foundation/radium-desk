<?php

namespace App\Services\Customer360;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\EInvoiceRecord;
use App\Models\Incident;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\Notifications\NotificationCustomerContactResolver;
use App\Services\Notifications\NotificationMailSender;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceIrnGuard;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\StatutoryInvoiceForIncidentResolver;
use App\Support\AppDateFormatter;

class Customer360StatutoryInvoicePresenter
{
    /**
     * @var array<string, string>
     */
    private const SAFE_REASONS = [
        'invoice_cancelled' => 'Invoice cancelled',
        'invalid_invoice_status' => 'Invoice is not issued',
        'unsupported_document_type' => 'Document type is not a tax invoice',
        'outside_invoice_scope' => 'Outside the statutory invoice date scope',
        'b2c_not_eligible' => 'B2C / not eligible',
        'invalid_buyer_gstin' => 'Buyer GSTIN is not valid',
        'incomplete_gst' => 'Statutory tax data is incomplete',
        'irp_fields_incomplete' => 'Statutory data incomplete',
        'buyer_address_exceeds_irp_limit' => 'Statutory data incomplete',
        'seller_address_exceeds_irp_limit' => 'Statutory data incomplete',
        'issuance_policy_service_excluded' => 'Not eligible for e-invoice under current policy',
        'issuance_policy_mixed_lines' => 'Not eligible for e-invoice under current policy',
        'issuance_policy_unknown' => 'Not eligible for e-invoice under current policy',
    ];

    public function __construct(
        private readonly StatutoryInvoiceForIncidentResolver $resolver,
        private readonly NotificationCustomerContactResolver $contacts,
        private readonly NotificationMailSender $mailSender,
        private readonly NotificationChannelAvailabilityService $channels,
        private readonly EInvoiceEligibility $eligibility,
        private readonly EInvoiceIrnPayloadMapper $mapper,
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
        $viewUrl = route('dashboard.service-cases.customer-360.invoices.pdf', [
            'incident' => $incident,
            'invoice' => $invoice,
        ]);

        return [
            'id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'issued_at_label' => AppDateFormatter::date($invoice->issued_at),
            'invoice_value' => number_format((float) $invoice->invoice_value, 2, '.', ''),
            'status_label' => $this->invoiceStatusLabel($invoice),
            'view_url' => $viewUrl,
            'download_url' => route('dashboard.service-cases.customer-360.invoices.download', [
                'incident' => $incident,
                'invoice' => $invoice,
            ]),
            'share_url' => $viewUrl,
            'share_title' => 'Tax invoice '.(string) $invoice->invoice_number,
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
            'einvoice' => $this->einvoicePresentation($invoice),
        ];
    }

    private function invoiceStatusLabel(StatutoryInvoice $invoice): string
    {
        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return 'Cancelled';
        }

        return 'Generated';
    }

    /**
     * @return array{
     *     status_label: string,
     *     irn: ?string,
     *     ack_no: ?string,
     *     ack_date: ?string,
     *     reason: ?string
     * }
     */
    private function einvoicePresentation(StatutoryInvoice $invoice): array
    {
        $record = $invoice->eInvoiceRecord;
        if (EInvoiceIrnGuard::recordHasIssuedIrn($record)) {
            return [
                'status_label' => 'IRN Generated',
                'irn' => trim((string) $record?->irn),
                'ack_no' => $this->nullableTrim($record?->ack_no),
                'ack_date' => AppDateFormatter::datetime24($record?->ack_date),
                'reason' => null,
            ];
        }

        $eligibility = $this->eligibility->evaluate($invoice);
        $skipReason = $this->storedSkipReason($record);

        if ($eligibility->reason === 'b2c_not_eligible' || $skipReason === 'b2c_not_eligible') {
            return $this->einvoiceState('Not Applicable', 'B2C / not eligible');
        }

        if (in_array($record?->status, [
            EInvoiceRecordStatus::Failed->value,
            EInvoiceRecordStatus::PermanentFailure->value,
        ], true)) {
            return $this->einvoiceState('Failed', $this->safeReason($skipReason) ?? 'E-invoice could not be issued');
        }

        if (str_starts_with((string) $eligibility->reason, 'issuance_policy_')
            || str_starts_with((string) $skipReason, 'issuance_policy_')) {
            return $this->einvoiceState('Not Applicable', $this->safeReason($eligibility->reason) ?? $this->safeReason($skipReason));
        }

        if (in_array($eligibility->reason, ['invoice_cancelled', 'invalid_invoice_status', 'unsupported_document_type', 'outside_invoice_scope', 'invalid_buyer_gstin'], true)) {
            return $this->einvoiceState('Failed', $this->safeReason($eligibility->reason));
        }

        if (in_array($eligibility->reason, ['incomplete_gst', 'irp_fields_incomplete'], true)
            || $skipReason === 'irp_fields_incomplete'
            || $skipReason === 'incomplete_gst'
            || ($eligibility->eligible && ! $this->mapper->map($invoice)->isSubmittable())) {
            return $this->einvoiceState('Failed', 'Statutory data incomplete');
        }

        if ($eligibility->eligible || in_array($record?->status, [
            EInvoiceRecordStatus::Queued->value,
            EInvoiceRecordStatus::Processing->value,
            EInvoiceRecordStatus::TemporaryFailure->value,
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecordStatus::IrnNotFound->value,
        ], true) || in_array($skipReason, ['worker_may_mint_off', 'provider_disabled', 'provider_http_disabled', 'b2b_eligible'], true)) {
            return $this->einvoiceState('Pending', null);
        }

        return $this->einvoiceState('Failed', $this->safeReason($eligibility->reason) ?? $this->safeReason($skipReason) ?? 'E-invoice status is unavailable');
    }

    /**
     * @return array{status_label: string, irn: null, ack_no: null, ack_date: null, reason: ?string}
     */
    private function einvoiceState(string $status, ?string $reason): array
    {
        return [
            'status_label' => $status,
            'irn' => null,
            'ack_no' => null,
            'ack_date' => null,
            'reason' => $reason,
        ];
    }

    private function storedSkipReason(?EInvoiceRecord $record): ?string
    {
        $reason = $record?->response_payload['skip_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private function safeReason(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return self::SAFE_REASONS[$reason] ?? null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
