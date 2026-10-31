<?php

namespace App\Services\RadiumBox;

use App\Models\StatutoryInvoice;

class RadiumBoxStatutoryReceiptPublicationPolicy
{
    public const DISABLED_MESSAGE = 'Publisher disabled pending Owner activation.';

    public const ALLOWLIST_BLOCKED_MESSAGE = 'Statutory invoice is not on the RadiumBox receipt publish allowlist pending Owner authorization.';

    /**
     * @return list<int>
     */
    public static function parseAllowedInvoiceIdsFromEnv(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $tokens = preg_split('/\s*,\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        $ids = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || ! preg_match('/^\d+$/', trim($token))) {
                continue;
            }

            $id = (int) trim($token);
            if ($id <= 0) {
                continue;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @return list<int>
     */
    public function allowedInvoiceIds(): array
    {
        /** @var list<int> $ids */
        $ids = config('radiumbox.statutory_receipt_publish.allowed_invoice_ids', []);

        return $ids;
    }

    public function mayDeliverHttp(StatutoryInvoice $invoice): bool
    {
        return $this->deliveryBlockedReason($invoice) === null;
    }

    public function deliveryBlockedReason(StatutoryInvoice $invoice): ?string
    {
        if (! config('radiumbox.statutory_receipt_publish.enabled', false)) {
            return self::DISABLED_MESSAGE;
        }

        $allowlist = $this->allowedInvoiceIds();
        if ($allowlist !== [] && ! in_array($invoice->id, $allowlist, true)) {
            return self::ALLOWLIST_BLOCKED_MESSAGE;
        }

        return null;
    }
}
