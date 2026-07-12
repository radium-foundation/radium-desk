<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Enums\CommunicationActionKey;
use App\Models\Incident;
use App\Models\Order;

class CommunicationActionVariableResolver
{
    /**
     * @param  array<string, mixed>  $operatorInput
     * @return array<string, mixed>
     */
    public function resolve(
        CommunicationActionDefinition $definition,
        Incident $incident,
        array $operatorInput = [],
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
            CommunicationActionKey::DriverInstallationGuide => array_merge($base, [
                'reference_number' => trim((string) ($input['reference_number'] ?? $base['reference'])),
                'whatsapp_body_values' => [
                    $base['customer_name'],
                    trim((string) ($input['reference_number'] ?? $base['reference'])),
                ],
            ]),
            CommunicationActionKey::ReviewRequest => array_merge($base, [
                'review_url' => (string) config('communication_actions.urls.review'),
                'whatsapp_body_values' => [
                    $base['customer_name'],
                    (string) config('communication_actions.urls.review'),
                ],
            ]),
            CommunicationActionKey::RefundConfirmation => array_merge($base, [
                'refund_amount' => trim((string) ($input['refund_amount'] ?? '')),
                'refund_reference' => trim((string) ($input['refund_reference'] ?? $base['reference'])),
                'whatsapp_body_values' => array_values(array_filter([
                    $base['customer_name'],
                    trim((string) ($input['refund_amount'] ?? '')),
                    trim((string) ($input['refund_reference'] ?? $base['reference'])),
                ], fn (string $value): bool => $value !== '')),
            ]),
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
