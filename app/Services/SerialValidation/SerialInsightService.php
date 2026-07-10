<?php

namespace App\Services\SerialValidation;

use App\Data\SerialInsight;
use App\Data\SerialValidationResult;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialInsightConfidence;
use App\Enums\SerialInsightStatus;
use App\Enums\SerialValidationStatus;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Str;

class SerialInsightService
{
    private const SUGGEST_CORRECT_SERIAL_VIA_WHATSAPP = 'Request the customer to share the correct serial number via WhatsApp.';

    private const SUGGEST_REQUEST_SERIAL_VIA_WHATSAPP = 'Request the customer to share the device serial number via WhatsApp.';

    private const SUGGEST_VERIFY_SERIAL_VIA_RADIUMBOX = 'Verify the serial number in RadiumBox before proceeding.';

    public function __construct(
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function analyze(Order $order): SerialInsight
    {
        if ($order->isInquiryOrder()) {
            return new SerialInsight(
                status: SerialInsightStatus::Valid,
                confidence: SerialInsightConfidence::High,
                explanation: 'Inquiry orders do not require a device serial number.',
            );
        }

        $serial = trim((string) $order->serial_number);

        if ($serial === '' || $this->placeholderService->isPlaceholder($serial)) {
            return new SerialInsight(
                status: SerialInsightStatus::Missing,
                confidence: SerialInsightConfidence::High,
                explanation: 'Device serial number is missing.',
                suggestedAction: self::SUGGEST_REQUEST_SERIAL_VIA_WHATSAPP,
                technicalReason: (string) config('serial_validation.placeholder_reason', 'Waiting for customer serial'),
            );
        }

        $validation = $this->serialValidationService->validateForOrder($serial, $order);
        $productLabel = DeviceModelFormatter::shortDisplay(
            $order->device_model ?: $order->product_name,
        ) ?: ($validation->product ?? 'device');

        if ($validation->status === SerialValidationStatus::Valid && ! $validation->requiresRadiumBoxVerification) {
            return new SerialInsight(
                status: SerialInsightStatus::Valid,
                confidence: SerialInsightConfidence::High,
                explanation: "Serial number matches the expected {$productLabel} format.",
                technicalReason: $validation->reason,
            );
        }

        if ($validation->status === SerialValidationStatus::Warning) {
            return new SerialInsight(
                status: SerialInsightStatus::Warning,
                confidence: SerialInsightConfidence::Medium,
                explanation: "Serial number looks unusual for {$productLabel}; verify with RadiumBox before proceeding.",
                suggestedAction: self::SUGGEST_CORRECT_SERIAL_VIA_WHATSAPP,
                technicalReason: $validation->reason,
            );
        }

        if ($validation->status === SerialValidationStatus::Pending) {
            return new SerialInsight(
                status: SerialInsightStatus::Pending,
                confidence: SerialInsightConfidence::High,
                explanation: 'Serial number is still pending from the customer.',
                suggestedAction: self::SUGGEST_REQUEST_SERIAL_VIA_WHATSAPP,
                technicalReason: $validation->reason,
            );
        }

        return $this->buildSuspiciousInsight($order, $validation, $productLabel, $serial);
    }

    private function buildSuspiciousInsight(
        Order $order,
        SerialValidationResult $validation,
        string $productLabel,
        string $serial,
    ): SerialInsight {
        $syncStatus = $this->syncStore->status($order->id);
        $technicalReason = $validation->reason;

        if ($this->looksLikeProductCode($serial, $validation)) {
            return new SerialInsight(
                status: SerialInsightStatus::Suspicious,
                confidence: SerialInsightConfidence::High,
                explanation: 'Customer may have submitted a product code instead of a serial number.',
                suggestedAction: self::SUGGEST_CORRECT_SERIAL_VIA_WHATSAPP,
                technicalReason: $technicalReason,
            );
        }

        if ($validation->isFail()
            && $syncStatus === RadiumBoxEnrichmentSyncStatus::Synced) {
            return new SerialInsight(
                status: SerialInsightStatus::Suspicious,
                confidence: SerialInsightConfidence::High,
                explanation: 'Serial number does not match RadiumBox data.',
                suggestedAction: self::SUGGEST_CORRECT_SERIAL_VIA_WHATSAPP,
                technicalReason: $technicalReason,
            );
        }

        if ($validation->isFail()) {
            $patternExplanation = $this->patternMismatchExplanation($productLabel, $validation);

            return new SerialInsight(
                status: SerialInsightStatus::Suspicious,
                confidence: SerialInsightConfidence::Medium,
                explanation: $patternExplanation,
                suggestedAction: self::SUGGEST_CORRECT_SERIAL_VIA_WHATSAPP,
                technicalReason: $technicalReason,
            );
        }

        if ($validation->status === SerialValidationStatus::Unsupported) {
            return new SerialInsight(
                status: SerialInsightStatus::Warning,
                confidence: SerialInsightConfidence::Medium,
                explanation: 'Product mapping is unclear; confirm device model before trusting this serial.',
                suggestedAction: self::SUGGEST_VERIFY_SERIAL_VIA_RADIUMBOX,
                technicalReason: $validation->reason,
            );
        }

        return new SerialInsight(
            status: SerialInsightStatus::Warning,
            confidence: SerialInsightConfidence::Low,
            explanation: 'Serial number needs a quick manual review.',
            suggestedAction: self::SUGGEST_VERIFY_SERIAL_VIA_RADIUMBOX,
            technicalReason: $technicalReason,
        );
    }

    private function looksLikeProductCode(string $serial, SerialValidationResult $validation): bool
    {
        $reason = Str::lower((string) $validation->reason);

        if (Str::contains($reason, ['product label', 'part number', 'placeholder text', 'voltage specification'])) {
            return true;
        }

        $normalized = Str::upper(preg_replace('/\s+/', '', $serial) ?? $serial);

        return Str::startsWith($normalized, ['54SAXX', 'PFSPL', 'FPSPL', 'P/N'])
            || in_array($normalized, ['MFS110', 'MIS100', 'FM220', 'MSOE3', 'PB1000', 'MARC11', 'KAMAL'], true);
    }

    private function patternMismatchExplanation(string $productLabel, SerialValidationResult $validation): string
    {
        $productKey = Str::upper(str_replace(' ', '', $productLabel));

        if (Str::contains($productKey, 'FM220')) {
            return 'This serial does not match the expected FM 220 pattern.';
        }

        if (Str::contains($productKey, 'MFS110')) {
            return 'This serial does not match the expected MFS 110 pattern.';
        }

        if (Str::contains($productKey, 'MIS100')) {
            return 'This serial does not match the expected MIS 100 pattern.';
        }

        if (Str::contains($productKey, 'MSOE3')) {
            return 'This serial does not match the expected MSO E3 pattern.';
        }

        return "This serial does not match the expected {$productLabel} pattern.";
    }
}
