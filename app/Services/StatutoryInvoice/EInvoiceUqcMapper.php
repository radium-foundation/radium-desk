<?php

namespace App\Services\StatutoryInvoice;

/**
 * Accepts a stored NIC e-Invoice UQC only. Does not default missing values to PCS/NOS.
 * Authoritative catalog source is inventory_products.uqc; mint copies onto statutory_invoice_items.uqc.
 */
final class EInvoiceUqcMapper
{
    /**
     * NIC e-Invoice quantity unit codes (P-07-09-181 master).
     * GGK/MLT/PCS are accepted. GGR is not on that master. Missing values
     * are not defaulted to PCS or NOS.
     *
     * @var list<string>
     */
    private const CODES = [
        'BAG', 'BAL', 'BDL', 'BKL', 'BOU', 'BOX', 'BTL', 'BUN', 'CAN', 'CBM',
        'CCM', 'CMS', 'CTN', 'DOZ', 'DRM', 'GGK', 'GMS', 'GRS', 'GYD', 'KGS',
        'KLR', 'KME', 'LTR', 'MLT', 'MTR', 'MTS', 'NOS', 'OTH', 'PAC', 'PCS',
        'PRS', 'QTL', 'ROL', 'SET', 'SQF', 'SQM', 'SQY', 'TBS', 'TGM', 'THD',
        'TON', 'TUB', 'UGS', 'UNT', 'YDS',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::CODES;
    }

    /**
     * Line UQC wins when valid. Catalog UQC is used only when the line has none.
     * Invalid stored values are not copied and are not replaced with pcs/NOS.
     */
    public function snapshot(?string $lineUqc, ?string $catalogUqc): ?string
    {
        return $this->resolveLineOrCatalog($lineUqc, $catalogUqc)['code'];
    }

    /**
     * GENERATE/mint resolution. Statutory line UQC is the snapshot when populated.
     * Catalog is consulted only when the line is empty. Invalid line codes are not
     * replaced by catalog. Missing values are not defaulted to PCS/NOS.
     *
     * @return array{code: ?string, gap: ?string}
     */
    public function resolveLineOrCatalog(?string $lineUqc, ?string $catalogUqc): array
    {
        $fromLine = $this->resolve($lineUqc);
        if ($fromLine['code'] !== null) {
            return $fromLine;
        }

        if ($fromLine['gap'] === 'unsupported_uqc') {
            return $fromLine;
        }

        return $this->resolve($catalogUqc);
    }

    /**
     * @return array{code: ?string, gap: ?string}
     */
    public function resolve(mixed $stored): array
    {
        $code = $this->normalize($stored);
        if ($code === null) {
            return ['code' => null, 'gap' => 'missing_uqc'];
        }

        if (! in_array($code, self::CODES, true)) {
            return ['code' => null, 'gap' => 'unsupported_uqc'];
        }

        return ['code' => $code, 'gap' => null];
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = strtoupper(trim((string) $value));

        return $trimmed === '' ? null : $trimmed;
    }
}
