<?php

namespace App\Services;

use App\Enums\SerialInsightStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\SerialValidation\SerialInsightService;
use App\Services\SerialValidation\SerialPlaceholderService;
use Illuminate\Validation\ValidationException;

class ServiceCaseCloseRequirementService
{
    public function __construct(
        private readonly SerialPlaceholderService $placeholderService,
        private readonly SerialInsightService $serialInsightService,
    ) {}

    /**
     * @return array<string, string>
     */
    public function validate(
        Incident $incident,
        bool $serialNumberUnavailable,
        bool $referenceNumberUnavailable,
    ): array {
        $messages = [];

        if (! $referenceNumberUnavailable && ! filled(trim((string) $incident->reference_no))) {
            $messages['reference_no'] = 'Reference Number is required before closing this service case.';
        }

        $order = $incident->order;

        if (! $serialNumberUnavailable && ! ($order?->isInquiryOrder() ?? false)) {
            if ($order === null || ! filled(trim((string) $order->serial_number))) {
                $messages['serial_number'] = 'Serial Number is required before closing this service case.';
            } elseif ($this->placeholderService->isPlaceholder((string) $order->serial_number)) {
                $messages['serial_number'] = 'Serial Number is required before closing this service case.';
            } elseif ($this->serialNeedsCorrection($order)) {
                $messages['serial_number'] = 'Serial number must be verified or corrected before closing this service case.';
            }
        }

        return $messages;
    }

    private function serialNeedsCorrection(Order $order): bool
    {
        $insight = $this->serialInsightService->analyze($order);

        return in_array($insight->status, [
            SerialInsightStatus::Suspicious,
            SerialInsightStatus::Warning,
        ], true);
    }

    /**
     * @throws ValidationException
     */
    public function ensureRequirementsMet(
        Incident $incident,
        bool $serialNumberUnavailable,
        bool $referenceNumberUnavailable,
    ): void {
        $messages = $this->validate($incident, $serialNumberUnavailable, $referenceNumberUnavailable);

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
