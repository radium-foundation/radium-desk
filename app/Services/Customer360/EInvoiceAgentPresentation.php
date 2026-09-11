<?php

namespace App\Services\Customer360;

/**
 * Customer-service-safe IRN blocker explanations.
 */
final class EInvoiceAgentPresentation
{
    /**
     * @var array<string, array{why: string, next_action: string}>
     */
    private const MESSAGES = [
        'missing_uqc' => [
            'why' => 'Product/service unit code is missing.',
            'next_action' => 'Statutory service classification is being completed.',
        ],
        'missing_buyer_pin' => [
            'why' => 'Buyer PIN code is missing.',
            'next_action' => 'Billing PIN code needs to be added before e-Invoice generation.',
        ],
        'missing_buyer_loc' => [
            'why' => 'Buyer city/location is missing.',
            'next_action' => 'Billing city/location needs to be added before e-Invoice generation.',
        ],
        'buyer_state_mismatch' => [
            'why' => 'Buyer GST registration state and transaction place of supply need verification.',
            'next_action' => 'Billing/place-of-supply details require verification.',
        ],
        'place_of_supply_unresolved' => [
            'why' => 'Place of supply could not be verified.',
            'next_action' => 'Billing/place-of-supply details require verification.',
        ],
        'buyer_address_exceeds_irp_limit' => [
            'why' => 'Billing address exceeds the statutory e-Invoice address limit.',
            'next_action' => 'Billing address needs correction before e-Invoice generation.',
        ],
        'missing_is_servc' => [
            'why' => 'Goods/service classification needs verification.',
            'next_action' => 'Statutory service classification is being completed.',
        ],
        'missing_billing_address' => [
            'why' => 'Structured billing address is incomplete.',
            'next_action' => 'Billing address details need to be completed before e-Invoice generation.',
        ],
        'invalid_isservc' => [
            'why' => 'Goods/service classification needs verification.',
            'next_action' => 'Statutory service classification is being completed.',
        ],
        'irp_fields_incomplete' => [
            'why' => 'Statutory data required for e-Invoice is incomplete.',
            'next_action' => 'Statutory invoice details are being completed.',
        ],
        'incomplete_gst' => [
            'why' => 'Statutory tax data is incomplete.',
            'next_action' => 'Tax details require verification before e-Invoice generation.',
        ],
    ];

    /**
     * @param  list<string>  $reasons
     * @return array{status_label: string, why: string, next_action: string, reasons: list<string>}
     */
    public function blocked(array $reasons): array
    {
        $primary = $reasons[0] ?? 'irp_fields_incomplete';
        $message = self::MESSAGES[$primary] ?? self::MESSAGES['irp_fields_incomplete'];

        return [
            'status_label' => 'Pending — Statutory data incomplete',
            'why' => $message['why'],
            'next_action' => $message['next_action'],
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array{status_label: string, why: string, next_action: string, reasons: list<string>}
     */
    public function posReview(): array
    {
        return [
            'status_label' => 'Pending — Place of supply requires verification',
            'why' => 'Buyer GST registration state and transaction place-of-supply data do not agree.',
            'next_action' => 'Billing/place-of-supply details require verification.',
            'reasons' => ['buyer_state_mismatch'],
        ];
    }
}
