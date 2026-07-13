<?php

namespace App\Services;

use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class WorkspaceCloseCasePayloadAdapter
{
    public function __construct(
        private readonly ServiceCaseCloseRequirementService $closeRequirementService,
        private readonly CustomerUnreachableCloseEligibilityService $customerUnreachableCloseEligibilityService,
    ) {}

    public function isV2Payload(array $payload): bool
    {
        return filled($payload['reason_for_closing'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function validateBeforeClose(Incident $incident, User $actor, array $payload): void
    {
        $reason = ServiceCaseCloseReasonForClosing::from((string) $payload['reason_for_closing']);

        if ($reason === ServiceCaseCloseReasonForClosing::CustomerNotResponding) {
            $ineligibilityReason = $this->customerUnreachableCloseEligibilityService->ineligibilityReason(
                $incident,
                ServiceCaseCloseExceptionReason::CustomerNotResponding,
                $actor,
            );

            if ($ineligibilityReason !== null) {
                throw ValidationException::withMessages([
                    'reason_for_closing' => $ineligibilityReason,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     body: string,
     *     serial_number_unavailable?: bool,
     *     reference_number_unavailable?: bool,
     *     serial_exception_reason?: string|null,
     *     serial_exception_reason_custom?: string|null,
     *     reference_exception_reason?: string|null,
     *     reference_exception_reason_custom?: string|null,
     *     notify_whatsapp?: bool,
     *     notify_email?: bool,
     * }
     */
    public function toLegacyPayload(Incident $incident, array $payload): array
    {
        $reason = ServiceCaseCloseReasonForClosing::from((string) $payload['reason_for_closing']);
        $metadata = $this->extractMetadata($payload);
        [$serialUnavailable, $referenceUnavailable] = $this->determineExceptionFlags($incident, $reason);

        $legacyReason = $this->mapToLegacyExceptionReason($reason);
        $customRemark = $this->buildCustomExceptionRemark($reason, $metadata);

        $notificationPreference = ServiceCaseCloseNotificationPreference::tryFrom(
            (string) ($payload['notification_preference'] ?? ServiceCaseCloseNotificationPreference::No->value),
        ) ?? ServiceCaseCloseNotificationPreference::No;

        $legacy = [
            'body' => (string) ($payload['body'] ?? ''),
            ...$notificationPreference->toLegacyNotifyFlags(),
        ];

        if ($serialUnavailable) {
            $legacy['serial_number_unavailable'] = true;
            $legacy['serial_exception_reason'] = $legacyReason->value;

            if ($legacyReason === ServiceCaseCloseExceptionReason::Other && filled($customRemark)) {
                $legacy['serial_exception_reason_custom'] = $customRemark;
            }
        }

        if ($referenceUnavailable) {
            $legacy['reference_number_unavailable'] = true;
            $legacy['reference_exception_reason'] = $legacyReason->value;

            if ($legacyReason === ServiceCaseCloseExceptionReason::Other && filled($customRemark)) {
                $legacy['reference_exception_reason_custom'] = $customRemark;
            }
        }

        return $legacy;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extractMetadata(array $payload): array
    {
        $metadata = [];

        foreach ([
            'expected_from',
            'expected_date',
            'contact_attempt',
            'attempts',
            'existing_case_id',
            'replacement_order_id',
            'approval_reference',
        ] as $key) {
            if (filled($payload[$key] ?? null)) {
                $metadata[$key] = match ($key) {
                    'attempts' => (int) $payload[$key],
                    default => trim((string) $payload[$key]),
                };
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     reason_for_closing: ServiceCaseCloseReasonForClosing,
     *     resolution_type: ServiceCaseCloseResolutionType|null,
     *     metadata: array<string, mixed>,
     *     closing_summary: string,
     *     notification_preference: ServiceCaseCloseNotificationPreference,
     * }
     */
    public function extractOutcomeData(array $payload): array
    {
        $notificationPreference = ServiceCaseCloseNotificationPreference::tryFrom(
            (string) ($payload['notification_preference'] ?? ServiceCaseCloseNotificationPreference::No->value),
        ) ?? ServiceCaseCloseNotificationPreference::No;

        $resolutionType = filled($payload['resolution_type'] ?? null)
            ? ServiceCaseCloseResolutionType::from((string) $payload['resolution_type'])
            : null;

        return [
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::from((string) $payload['reason_for_closing']),
            'resolution_type' => $resolutionType,
            'metadata' => $this->extractMetadata($payload),
            'closing_summary' => (string) ($payload['body'] ?? ''),
            'notification_preference' => $notificationPreference,
        ];
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function determineExceptionFlags(Incident $incident, ServiceCaseCloseReasonForClosing $reason): array
    {
        if ($reason === ServiceCaseCloseReasonForClosing::ReferenceNumberPending) {
            return [false, true];
        }

        if ($reason === ServiceCaseCloseReasonForClosing::SerialNumberPending) {
            return [true, false];
        }

        if (in_array($reason, [
            ServiceCaseCloseReasonForClosing::IssueResolved,
            ServiceCaseCloseReasonForClosing::CustomerCancelled,
            ServiceCaseCloseReasonForClosing::WarrantyRejected,
            ServiceCaseCloseReasonForClosing::PaymentCollectedOffline,
            ServiceCaseCloseReasonForClosing::Other,
        ], true)) {
            return [false, false];
        }

        $messages = $this->closeRequirementService->validate($incident, false, false);

        return [
            isset($messages['serial_number']),
            isset($messages['reference_no']),
        ];
    }

    private function mapToLegacyExceptionReason(ServiceCaseCloseReasonForClosing $reason): ServiceCaseCloseExceptionReason
    {
        return match ($reason) {
            ServiceCaseCloseReasonForClosing::CustomerCancelled => ServiceCaseCloseExceptionReason::CustomerCancelledBeforePayment,
            ServiceCaseCloseReasonForClosing::CustomerNotResponding => ServiceCaseCloseExceptionReason::CustomerNotResponding,
            ServiceCaseCloseReasonForClosing::WarrantyRejected => ServiceCaseCloseExceptionReason::WarrantyRejected,
            ServiceCaseCloseReasonForClosing::ReplacementIssued => ServiceCaseCloseExceptionReason::ReplacementIssued,
            ServiceCaseCloseReasonForClosing::PaymentCollectedOffline => ServiceCaseCloseExceptionReason::CashCollectedOffline,
            ServiceCaseCloseReasonForClosing::DuplicateCase => ServiceCaseCloseExceptionReason::DuplicateServiceCase,
            ServiceCaseCloseReasonForClosing::ApprovedByAdmin => ServiceCaseCloseExceptionReason::ApprovedByAdmin,
            ServiceCaseCloseReasonForClosing::ReferenceNumberPending,
            ServiceCaseCloseReasonForClosing::SerialNumberPending,
            ServiceCaseCloseReasonForClosing::Other => ServiceCaseCloseExceptionReason::Other,
            ServiceCaseCloseReasonForClosing::IssueResolved => ServiceCaseCloseExceptionReason::Other,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function buildCustomExceptionRemark(ServiceCaseCloseReasonForClosing $reason, array $metadata): ?string
    {
        return match ($reason) {
            ServiceCaseCloseReasonForClosing::ReferenceNumberPending => filled($metadata['expected_from'] ?? null)
                ? sprintf(
                    'Reference number pending from %s%s',
                    $metadata['expected_from'],
                    filled($metadata['expected_date'] ?? null) ? ' by '.$metadata['expected_date'] : '',
                )
                : 'Reference number pending',
            ServiceCaseCloseReasonForClosing::SerialNumberPending => filled($metadata['expected_from'] ?? null)
                ? sprintf(
                    'Serial number pending from %s%s',
                    $metadata['expected_from'],
                    filled($metadata['expected_date'] ?? null) ? ' by '.$metadata['expected_date'] : '',
                )
                : 'Serial number pending',
            ServiceCaseCloseReasonForClosing::DuplicateCase => filled($metadata['existing_case_id'] ?? null)
                ? 'Duplicate of case '.$metadata['existing_case_id']
                : null,
            ServiceCaseCloseReasonForClosing::ReplacementIssued => filled($metadata['replacement_order_id'] ?? null)
                ? 'Replacement order '.$metadata['replacement_order_id']
                : null,
            ServiceCaseCloseReasonForClosing::ApprovedByAdmin => filled($metadata['approval_reference'] ?? null)
                ? 'Approved by admin: '.$metadata['approval_reference']
                : null,
            ServiceCaseCloseReasonForClosing::CustomerNotResponding => $this->customerNotRespondingCustomRemark($metadata),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function customerNotRespondingCustomRemark(array $metadata): ?string
    {
        $parts = [];

        if (filled($metadata['contact_attempt'] ?? null)) {
            $parts[] = 'Contact attempt: '.$metadata['contact_attempt'];
        }

        if (filled($metadata['attempts'] ?? null)) {
            $parts[] = 'Attempts: '.$metadata['attempts'];
        }

        return $parts === [] ? null : implode('; ', $parts);
    }
}
