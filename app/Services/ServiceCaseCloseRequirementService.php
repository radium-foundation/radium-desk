<?php

namespace App\Services;

use App\Models\Incident;
use App\Services\SerialValidation\SerialPlaceholderService;
use Illuminate\Validation\ValidationException;

class ServiceCaseCloseRequirementService
{
    public function __construct(
        private readonly SerialPlaceholderService $placeholderService,
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

        if (! $serialNumberUnavailable) {
            if ($order === null || ! filled(trim((string) $order->serial_number))) {
                $messages['serial_number'] = 'Serial Number is required before closing this service case.';
            } elseif ($this->placeholderService->isPlaceholder((string) $order->serial_number)) {
                $messages['serial_number'] = 'Serial Number is required before closing this service case.';
            }
        }

        return $messages;
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
