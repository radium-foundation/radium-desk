<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionKey;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CommunicationActions\Commercial\CommercialCatalogSupportService;
use App\Services\CommunicationActions\DriverInstallationGuide\DriverInstallationGuideSupportService;
use App\Services\CommunicationActions\RefundConfirmation\RefundConfirmationSupportService;

class CommunicationActionVariableResolver
{
    public function __construct(
        private readonly DriverInstallationGuideSupportService $driverInstallationGuideSupportService,
        private readonly RefundConfirmationSupportService $refundConfirmationSupportService,
        private readonly CommercialCatalogSupportService $commercialCatalogSupportService,
    ) {}

    /**
     * @param  array<string, mixed>  $operatorInput
     * @return array<string, mixed>
     */
    public function resolve(
        CommunicationActionDefinition $definition,
        Incident $incident,
        array $operatorInput = [],
        ?User $operator = null,
    ): array {
        $incident->loadMissing('order');
        $order = $incident->order;

        $base = [
            'customer_name' => $this->customerName($order),
            'reference' => trim((string) ($incident->reference_no ?? '')),
            'order_id' => trim((string) ($order?->order_id ?? '')),
        ];

        $input = $this->sanitizeOperatorInput($definition, $operatorInput);
        $communicationTarget = trim((string) ($operatorInput['communication_target'] ?? ''));

        $resolved = match ($definition->key) {
            CommunicationActionKey::DriverInstallationGuide => $this->resolveDriverInstallationGuide(
                base: $base,
                order: $order,
                operator: $operator,
                communicationTarget: $communicationTarget,
            ),
            CommunicationActionKey::ReviewRequest => $this->resolveReviewRequest(
                base: $base,
                communicationTarget: $communicationTarget,
            ),
            CommunicationActionKey::RefundConfirmation => $this->resolveRefundConfirmation(
                base: $base,
                incident: $incident,
                order: $order,
            ),
            CommunicationActionKey::BuyRdService => $this->resolveBuyRdService(
                base: $base,
                order: $order,
                communicationTarget: $communicationTarget,
            ),
            CommunicationActionKey::BuyProduct => $this->resolveBuyProduct(
                base: $base,
                order: $order,
                communicationTarget: $communicationTarget,
            ),
        };

        return array_merge($input, $resolved);
    }

    public function resolveFromContext(CommunicationActionExecutionContext $context): array
    {
        return $this->resolve(
            definition: $context->action,
            incident: $context->incident,
            operatorInput: $context->operatorInput(),
            operator: $context->operator,
        );
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, mixed>
     */
    private function resolveRefundConfirmation(
        array $base,
        Incident $incident,
        ?Order $order,
    ): array {
        $refund = $this->refundConfirmationSupportService->resolveApprovedRefund($incident);
        $refundAmount = $refund instanceof RefundRequest
            ? $this->refundConfirmationSupportService->formatRefundAmount($refund)
            : '';
        $refundReference = $refund instanceof RefundRequest
            ? trim((string) $refund->reference_no)
            : '';
        $orderNumber = trim((string) ($order?->order_id ?? ''));
        $caseNumber = $base['reference'];
        $refundMethod = $refund?->approved_refund_method?->label() ?? '';
        $totalDeduction = $refund instanceof RefundRequest
            ? number_format((float) ($refund->total_deduction ?? 0), 2)
            : '';
        $executionReference = trim((string) ($refund?->execution_reference_no ?? ''));
        $remarks = trim((string) ($refund?->execution_remarks ?? $refund?->review_notes ?? ''));

        return array_merge($base, [
            'company_name' => $this->refundConfirmationSupportService->companyName(),
            'support_contact' => $this->refundConfirmationSupportService->supportContact(),
            'refund_amount' => $refundAmount,
            'refund_reference' => $refundReference,
            'refund_method' => $refundMethod,
            'deduction' => $totalDeduction,
            'reference_number' => $executionReference !== '' ? $executionReference : $refundReference,
            'remarks' => $remarks,
            'order_number' => $orderNumber,
            'case_number' => $caseNumber,
            'whatsapp_body_values' => array_values(array_filter([
                $base['customer_name'],
                $refundAmount,
                $refundReference,
            ], fn (string $value): bool => $value !== '')),
        ]);
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, mixed>
     */
    private function resolveBuyRdService(array $base, ?Order $order, string $communicationTarget = ''): array
    {
        $buyRdServiceUrl = $this->resolveBuyRdServiceUrl($order, $communicationTarget) ?? '';
        $buttonSuffix = $this->urlPathSuffix($buyRdServiceUrl);

        return array_merge($base, [
            'company_name' => $this->commercialCatalogSupportService->companyName(),
            'buy_rd_service_url' => $buyRdServiceUrl,
            'support_contact' => $this->commercialCatalogSupportService->supportContact(),
            'whatsapp_body_values' => array_values(array_filter([
                $base['customer_name'],
            ], fn (string $value): bool => $value !== '')),
            'whatsapp_button_values' => array_values(array_filter([
                $buttonSuffix,
            ], fn (string $value): bool => $value !== '')),
        ]);
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, mixed>
     */
    private function resolveBuyProduct(array $base, ?Order $order, string $communicationTarget = ''): array
    {
        $buyDeviceUrl = $this->resolveBuyDeviceUrl($order, $communicationTarget) ?? '';
        $buttonSuffix = $this->urlPathSuffix($buyDeviceUrl);

        return array_merge($base, [
            'company_name' => $this->commercialCatalogSupportService->companyName(),
            'buy_device_url' => $buyDeviceUrl,
            'support_contact' => $this->commercialCatalogSupportService->supportContact(),
            'whatsapp_body_values' => array_values(array_filter([
                $base['customer_name'],
            ], fn (string $value): bool => $value !== '')),
            'whatsapp_button_values' => array_values(array_filter([
                $buttonSuffix,
            ], fn (string $value): bool => $value !== '')),
        ]);
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, mixed>
     */
    private function resolveReviewRequest(array $base, string $communicationTarget = ''): array
    {
        $reviewUrl = $this->resolveReviewPlatformUrl($communicationTarget);
        $companyName = trim((string) config('communication_actions.company_name', 'Radium Box'));
        $supportContact = trim((string) config('communication_actions.support_contact', 'support@radiumbox.com'));

        return array_merge($base, [
            'review_url' => $reviewUrl,
            'company_name' => $companyName,
            'support_contact' => $supportContact,
            'whatsapp_body_values' => array_values(array_filter([
                $base['customer_name'],
                $reviewUrl,
            ], fn (string $value): bool => $value !== '')),
        ]);
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, mixed>
     */
    private function resolveDriverInstallationGuide(
        array $base,
        ?Order $order,
        ?User $operator,
        string $communicationTarget = '',
    ): array {
        $deviceModel = $this->resolveDeviceModelTarget($communicationTarget);

        if ($deviceModel !== null) {
            $driverDownloadLink = $this->driverInstallationGuideSupportService
                ->resolveDriverDownloadLinkForDeviceModel($deviceModel) ?? '';
            $modelName = $this->driverInstallationGuideSupportService
                ->resolveModelNameForDeviceModel($deviceModel);
        } else {
            $driverDownloadLink = $this->driverInstallationGuideSupportService->resolveDriverDownloadLink($order) ?? '';
            $modelName = $this->driverInstallationGuideSupportService->resolveModelName($order);
        }

        $buttonSuffix = $this->urlPathSuffix($driverDownloadLink);
        $supportContact = $this->driverInstallationGuideSupportService->supportContact();
        $companyName = $this->driverInstallationGuideSupportService->companyName();
        $restartInstructions = $this->driverInstallationGuideSupportService->restartInstructions();
        $agentName = trim((string) ($operator?->name ?? 'Support Team'));
        $caseNumber = $base['reference'];

        return array_merge($base, [
            'agent_name' => $agentName !== '' ? $agentName : 'Support Team',
            'case_number' => $caseNumber,
            'reference_number' => $caseNumber,
            'model_name' => $modelName,
            'driver_download_link' => $driverDownloadLink,
            'support_contact' => $supportContact,
            'company_name' => $companyName,
            'restart_instructions' => $restartInstructions,
            'whatsapp_body_values' => array_values(array_filter([
                $base['customer_name'],
            ], fn (string $value): bool => $value !== '')),
            'whatsapp_button_values' => array_values(array_filter([
                $buttonSuffix,
            ], fn (string $value): bool => $value !== '')),
        ]);
    }

    private function resolveBuyRdServiceUrl(?Order $order, string $communicationTarget = ''): ?string
    {
        $deviceModel = $this->resolveDeviceModelTarget($communicationTarget);

        if ($deviceModel !== null) {
            return $this->commercialCatalogSupportService->resolveBuyRdServiceUrlForDeviceModel($deviceModel);
        }

        return $this->commercialCatalogSupportService->resolveBuyRdServiceUrl($order);
    }

    private function resolveBuyDeviceUrl(?Order $order, string $communicationTarget = ''): ?string
    {
        $deviceModel = $this->resolveDeviceModelTarget($communicationTarget);

        if ($deviceModel !== null) {
            return $this->commercialCatalogSupportService->resolveBuyDeviceUrlForDeviceModel($deviceModel);
        }

        return $this->commercialCatalogSupportService->resolveBuyDeviceUrl($order);
    }

    private function resolveReviewPlatformUrl(string $communicationTarget = ''): string
    {
        if ($communicationTarget !== '') {
            foreach (config('communication_actions.review_platforms', []) as $platform) {
                if (($platform['key'] ?? null) === $communicationTarget) {
                    $url = trim((string) ($platform['url'] ?? ''));

                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        return trim((string) config('communication_actions.urls.review'));
    }

    private function resolveDeviceModelTarget(string $communicationTarget): ?DeviceModel
    {
        if ($communicationTarget === '' || ! ctype_digit($communicationTarget)) {
            return null;
        }

        return DeviceModel::query()
            ->where('is_active', true)
            ->find((int) $communicationTarget);
    }

    /**
     * Extract the path suffix from a URL for Meta WhatsApp dynamic URL buttons.
     * Example: https://ra8.in/driver-mfs110 → driver-mfs110
     */
    private function urlPathSuffix(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '' || $path === '/') {
            return '';
        }

        return ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $operatorInput
     * @return array<string, string>
     */
    private function sanitizeOperatorInput(
        CommunicationActionDefinition $definition,
        array $operatorInput,
    ): array {
        $sanitized = [];

        foreach ($definition->variables as $variable) {
            if (! array_key_exists($variable->key, $operatorInput)) {
                continue;
            }

            $sanitized[$variable->key] = trim((string) $operatorInput[$variable->key]);
        }

        return $sanitized;
    }

    private function customerName(?Order $order): string
    {
        $name = trim((string) ($order?->customer_name ?? ''));

        return $name !== '' ? $name : 'Customer';
    }
}
