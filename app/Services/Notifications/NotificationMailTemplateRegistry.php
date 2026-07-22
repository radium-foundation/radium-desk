<?php

namespace App\Services\Notifications;

use App\Data\NotificationMailTemplateDefinition;
use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Models\Order;
use App\Services\SupportAppointmentUrlService;
use App\Services\SupportContactResolver;

class NotificationMailTemplateRegistry
{
    public function __construct(
        private readonly SupportAppointmentUrlService $supportAppointmentUrlService,
        private readonly SupportContactResolver $supportContactResolver,
    ) {}
    public function resolve(NotificationType $type): ?NotificationMailTemplateDefinition
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => new NotificationMailTemplateDefinition(
                subject: 'Help Us Complete Your Device Setup',
                view: 'emails.notifications.request-serial-number',
                requiredVariables: ['customer_name', 'booking_url'],
            ),
            NotificationType::RequestCorrectSerial => new NotificationMailTemplateDefinition(
                subject: 'Please Confirm Your Device Serial Number',
                view: 'emails.notifications.request-correct-serial',
                requiredVariables: ['customer_name', 'order_id', 'booking_url'],
            ),
            NotificationType::CustomerWaitingFollowup => new NotificationMailTemplateDefinition(
                subject: 'Support Reminder: Request {reference} waiting for your response',
                view: 'emails.notifications.customer-waiting-followup',
                requiredVariables: ['customer_name', 'reference', 'booking_url'],
            ),
            NotificationType::CallbackSchedule => new NotificationMailTemplateDefinition(
                subject: 'We tried reaching you - schedule your callback',
                view: 'emails.notifications.callback-schedule',
                requiredVariables: ['customer_name', 'reference', 'booking_url'],
            ),
            NotificationType::FinalReminderBeforeClosure => new NotificationMailTemplateDefinition(
                subject: 'Final reminder before we close your support request {reference}',
                view: 'emails.notifications.final-reminder-before-closure',
                requiredVariables: ['customer_name', 'reference', 'booking_url'],
            ),
            NotificationType::SupportAppointmentBooked => new NotificationMailTemplateDefinition(
                subject: 'Your Support Appointment Is Confirmed',
                view: 'emails.notifications.support-appointment-booked',
                requiredVariables: ['customer_name', 'order_id', 'preferred_date', 'preferred_time_slot'],
            ),
            NotificationType::ServiceCaseClosed => new NotificationMailTemplateDefinition(
                subject: 'Your Service Case Is Complete',
                view: 'emails.notifications.service-case-closed',
                requiredVariables: ['customer_name', 'reference'],
            ),
            NotificationType::DriverInstallationGuide => new NotificationMailTemplateDefinition(
                subject: 'Driver Installation Guide for Your Device',
                view: 'emails.notifications.driver-installation-guide',
                requiredVariables: [
                    'customer_name',
                    'driver_download_link',
                    'company_name',
                ],
            ),
            NotificationType::ReviewRequest => new NotificationMailTemplateDefinition(
                subject: 'How Was Your Experience with Radium?',
                view: 'emails.notifications.review-request',
                requiredVariables: [
                    'customer_name',
                    'company_name',
                    'review_url',
                ],
            ),
            NotificationType::RefundConfirmation => new NotificationMailTemplateDefinition(
                subject: 'Your Refund Has Been Processed',
                view: 'emails.notifications.refund-confirmation',
                requiredVariables: [
                    'customer_name',
                    'company_name',
                    'refund_amount',
                    'refund_reference',
                ],
            ),
            NotificationType::BuyRdService => new NotificationMailTemplateDefinition(
                subject: 'Protect Your Device with RD Service',
                view: 'emails.notifications.buy-rd-service',
                requiredVariables: [
                    'customer_name',
                    'company_name',
                    'buy_rd_service_url',
                ],
            ),
            NotificationType::BuyProduct => new NotificationMailTemplateDefinition(
                subject: 'Recommended Product for Your Device',
                view: 'emails.notifications.buy-product',
                requiredVariables: [
                    'customer_name',
                    'company_name',
                    'buy_device_url',
                ],
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function variablesFor(NotificationMessage $message): array
    {
        $defaults = match ($message->type) {
            NotificationType::RequestSerialNumber => [
                'customer_name' => $this->resolveCustomerName($message),
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::RequestCorrectSerial => [
                'customer_name' => $this->resolveCustomerName($message),
                'order_id' => $message->customer instanceof Order
                    ? trim((string) $message->customer->order_id)
                    : '',
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::CustomerWaitingFollowup => [
                'customer_name' => $this->resolveCustomerName($message),
                'reference' => trim((string) ($message->incident->reference_no ?? '')),
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::CallbackSchedule => [
                'customer_name' => $this->resolveCustomerName($message),
                'reference' => trim((string) ($message->incident->reference_no ?? '')),
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::FinalReminderBeforeClosure => [
                'customer_name' => $this->resolveCustomerName($message),
                'reference' => trim((string) ($message->incident->reference_no ?? '')),
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::SupportAppointmentBooked => [
                'customer_name' => $this->resolveCustomerName($message),
                'order_id' => (string) ($message->variables['order_id'] ?? ''),
                'preferred_date' => (string) ($message->variables['preferred_date'] ?? ''),
                'preferred_time_slot' => (string) ($message->variables['preferred_time_slot'] ?? ''),
            ],
            NotificationType::ServiceCaseClosed => [
                'customer_name' => $this->resolveCustomerName($message),
                'reference' => trim((string) ($message->incident->reference_no ?? '')),
            ],
            NotificationType::DriverInstallationGuide => [
                'customer_name' => $this->resolveCustomerName($message),
                'agent_name' => trim((string) ($message->variables['agent_name'] ?? $message->actor?->name ?? 'Support Team')),
                'case_number' => trim((string) ($message->variables['case_number'] ?? $message->incident->reference_no ?? '')),
                'reference_number' => trim((string) ($message->variables['reference_number'] ?? $message->incident->reference_no ?? '')),
                'model_name' => trim((string) ($message->variables['model_name'] ?? '')),
                'driver_download_link' => trim((string) ($message->variables['driver_download_link'] ?? '')),
                'company_name' => trim((string) ($message->variables['company_name'] ?? config('communication_actions.company_name'))),
                'support_booking_link' => $this->resolveSupportBookingLink($message),
                'restart_instructions' => trim((string) ($message->variables['restart_instructions'] ?? '')),
                'order_id' => $message->customer instanceof Order
                    ? trim((string) $message->customer->order_id)
                    : '',
            ],
            NotificationType::ReviewRequest => [
                'customer_name' => $this->resolveCustomerName($message),
                'company_name' => trim((string) ($message->variables['company_name'] ?? config('communication_actions.company_name'))),
                'review_url' => trim((string) ($message->variables['review_url'] ?? config('communication_actions.urls.review'))),
            ],
            NotificationType::RefundConfirmation => [
                'customer_name' => $this->resolveCustomerName($message),
                'company_name' => trim((string) ($message->variables['company_name'] ?? config('communication_actions.company_name'))),
                'refund_amount' => trim((string) ($message->variables['refund_amount'] ?? '')),
                'refund_reference' => trim((string) ($message->variables['refund_reference'] ?? '')),
                'order_number' => trim((string) ($message->variables['order_number'] ?? '')),
                'case_number' => trim((string) ($message->variables['case_number'] ?? $message->incident->reference_no ?? '')),
            ],
            NotificationType::BuyRdService => [
                'customer_name' => $this->resolveCustomerName($message),
                'company_name' => trim((string) ($message->variables['company_name'] ?? config('communication_actions.company_name'))),
                'buy_rd_service_url' => trim((string) ($message->variables['buy_rd_service_url'] ?? '')),
            ],
            NotificationType::BuyProduct => [
                'customer_name' => $this->resolveCustomerName($message),
                'company_name' => trim((string) ($message->variables['company_name'] ?? config('communication_actions.company_name'))),
                'buy_device_url' => trim((string) ($message->variables['buy_device_url'] ?? '')),
            ],
        };

        return $this->supportContactResolver->mergeIntoVariables(
            array_merge($defaults, $message->variables),
        );
    }

    public function subjectFor(NotificationType $type, NotificationMessage $message): string
    {
        $template = $this->resolve($type);

        if ($template === null) {
            return '';
        }

        $subject = $template->subject;

        foreach ($this->variablesFor($message) as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $subject = str_replace('{'.$key.'}', (string) $value, $subject);
        }

        return $subject;
    }

    private function resolveCustomerName(NotificationMessage $message): string
    {
        $customer = $message->customer;

        if ($customer instanceof Order) {
            $name = trim((string) ($customer->customer_name ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return 'Customer';
    }

    private function resolveSupportBookingLink(NotificationMessage $message): string
    {
        $explicitLink = trim((string) ($message->variables['support_booking_link'] ?? ''));

        if ($explicitLink !== '') {
            return $explicitLink;
        }

        if ($message->incident === null) {
            return '';
        }

        return $this->supportAppointmentUrlService->bookingUrl($message->incident);
    }
}
