<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionKey;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CommunicationActions\DriverInstallationGuide\DriverInstallationGuideSupportService;
use App\Services\CommunicationActions\RefundConfirmation\RefundConfirmationSupportService;

class CommunicationActionVariableResolver
{
    public function __construct(
        private readonly DriverInstallationGuideSupportService $driverInstallationGuideSupportService,
        private readonly RefundConfirmationSupportService $refundConfirmationSupportService,
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

        $resolved = match ($definition->key) {
            CommunicationActionKey::DriverInstallationGuide => $this->resolveDriverInstallationGuide(
                base: $base,
                order: $order,
                operator: $operator,
            ),
            CommunicationActionKey::ReviewRequest => $this->resolveReviewRequest($base),
            CommunicationActionKey::RefundConfirmation => $this->resolveRefundConfirmation(
                base: $base,
                incident: $incident,
                order: $order,
            ),
            CommunicationActionKey::BuyRdService => array_merge($base, [
                'purchase_url' => (string) config('communication_actions.urls.buy_rd_service'),
                'whatsapp_body_values' => [
                    $base['customer_name'],
                    (string) config('communication_actions.urls.buy_rd_service'),
                ],
            ]),
            CommunicationActionKey::BuyProduct => array_merge($base, [
                'product_name' => trim((string) ($input['product_name'] ?? ($order?->product_name ?? ''))),
                'purchase_url' => (string) config('communication_actions.urls.buy_product'),
                'whatsapp_body_values' => array_values(array_filter([
                    $base['customer_name'],
                    trim((string) ($input['product_name'] ?? ($order?->product_name ?? ''))),
                    (string) config('communication_actions.urls.buy_product'),
                ], fn (string $value): bool => $value !== '')),
            ]),
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

        return array_merge($base, [
            'company_name' => $this->refundConfirmationSupportService->companyName(),
            'support_contact' => $this->refundConfirmationSupportService->supportContact(),
            'refund_amount' => $refundAmount,
            'refund_reference' => $refundReference,
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
    private function resolveReviewRequest(array $base): array
    {
        $reviewUrl = trim((string) config('communication_actions.urls.review'));
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
    ): array {
        $driverDownloadLink = $this->driverInstallationGuideSupportService->resolveDriverDownloadLink($order) ?? '';
        $modelName = $this->driverInstallationGuideSupportService->resolveModelName($order);
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
                $modelName,
                $driverDownloadLink,
                $caseNumber,
                $supportContact,
            ], fn (string $value): bool => $value !== '')),
        ]);
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
