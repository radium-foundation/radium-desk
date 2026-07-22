<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use Illuminate\Validation\Rule;

trait ValidatesCloseCaseV2Fields
{
    protected function isCloseV2(): bool
    {
        return $this->filled('reason_for_closing');
    }

    /**
     * @return array<string, mixed>
     */
    protected function closeCaseV2Rules(): array
    {
        $reason = ServiceCaseCloseReasonForClosing::tryFrom((string) $this->input('reason_for_closing'));

        return [
            'reason_for_closing' => ['required', 'string', Rule::in(ServiceCaseCloseReasonForClosing::values())],
            'resolution_type' => [
                'nullable',
                'string',
                Rule::in(ServiceCaseCloseResolutionType::values()),
            ],
            'notification_preference' => [
                'nullable',
                'string',
                Rule::in(ServiceCaseCloseNotificationPreference::values()),
            ],
            'cnr_communication_preference' => [
                Rule::requiredIf(fn (): bool => $reason === ServiceCaseCloseReasonForClosing::CustomerNotResponding),
                'nullable',
                'string',
                Rule::in([
                    ServiceCaseCloseNotificationPreference::WhatsApp->value,
                    ServiceCaseCloseNotificationPreference::Email->value,
                    ServiceCaseCloseNotificationPreference::Both->value,
                ]),
            ],
            'expected_from' => [
                Rule::requiredIf(fn (): bool => in_array($reason, [
                    ServiceCaseCloseReasonForClosing::ReferenceNumberPending,
                    ServiceCaseCloseReasonForClosing::SerialNumberPending,
                ], true)),
                'nullable',
                'string',
                Rule::in(['customer', 'admin', 'distributor']),
            ],
            'expected_date' => ['nullable', 'date'],
            'existing_case_id' => [
                Rule::requiredIf(fn (): bool => $reason === ServiceCaseCloseReasonForClosing::DuplicateCase),
                'nullable',
                'string',
                'max:255',
            ],
            'replacement_order_id' => [
                Rule::requiredIf(fn (): bool => $reason === ServiceCaseCloseReasonForClosing::ReplacementIssued),
                'nullable',
                'string',
                'max:255',
            ],
            'approval_reference' => [
                Rule::requiredIf(fn (): bool => $reason === ServiceCaseCloseReasonForClosing::ApprovedByAdmin),
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function closeCaseV2Attributes(): array
    {
        return [
            'reason_for_closing' => 'reason for closing',
            'resolution_type' => 'resolution type',
            'notification_preference' => 'customer notification',
            'cnr_communication_preference' => 'communication channel',
            'expected_from' => 'expected from',
            'expected_date' => 'expected date',
            'existing_case_id' => 'existing case ID',
            'replacement_order_id' => 'replacement order ID',
            'approval_reference' => 'approval reference',
        ];
    }

    protected function prepareCloseCaseV2ForValidation(): void
    {
        if (! $this->isCloseV2()) {
            return;
        }

        $reason = ServiceCaseCloseReasonForClosing::tryFrom((string) $this->input('reason_for_closing'));

        if ($reason === ServiceCaseCloseReasonForClosing::CustomerNotResponding) {
            return;
        }

        if ($reason !== null && ! $reason->showsCustomerNotification()) {
            $this->merge([
                'notification_preference' => ServiceCaseCloseNotificationPreference::No->value,
            ]);
        }

        if (! filled($this->input('notification_preference'))) {
            $this->merge([
                'notification_preference' => ServiceCaseCloseNotificationPreference::No->value,
            ]);
        }
    }
}
