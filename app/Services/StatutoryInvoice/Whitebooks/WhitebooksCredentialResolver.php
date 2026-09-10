<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\StatutorySellerIdentity;

/**
 * Maps Desk issuer GSTIN → WhiteBooks client pair + per-GSTIN GST portal login.
 * Does not read media.radiumbox.com or Admin branch APIs.
 */
final class WhitebooksCredentialResolver
{
    public function __construct(
        private readonly StatutorySellerIdentity $sellers,
    ) {}

    /**
     * @return list<string>
     */
    public function missingReasons(StatutoryInvoice $invoice): array
    {
        $reasons = [];
        $base = $this->nullable(config('statutory_invoices.einvoice.gsp_base_url'))
            ?? 'https://api.whitebooks.in';
        if (! str_starts_with($base, 'https://')) {
            $reasons[] = 'invalid_gsp_base_url';
        }

        foreach (['gsp_client_id' => 'missing_gsp_client_id', 'gsp_client_secret' => 'missing_gsp_client_secret', 'gsp_email' => 'missing_gsp_email'] as $key => $reason) {
            if ($this->nullable(config('statutory_invoices.einvoice.'.$key)) === null) {
                $reasons[] = $reason;
            }
        }

        $ip = $this->nullable(config('statutory_invoices.einvoice.gsp_ip_address'));
        if ($ip === null) {
            $reasons[] = 'missing_gsp_ip_address';
        } elseif (! $this->isConfiguredIpv4($ip)) {
            $reasons[] = 'invalid_gsp_ip_address';
        }

        $sellerGstin = BuyerGstin::normalize($invoice->seller_gstin);
        $location = $this->sellers->locationForGstin($sellerGstin);
        if ($location === null) {
            $reasons[] = 'unmapped_seller_gstin';

            return array_values(array_unique($reasons));
        }

        $issuer = config('statutory_invoices.einvoice.issuers.'.$location, []);
        if (! is_array($issuer)) {
            $reasons[] = 'missing_issuer_credentials';

            return array_values(array_unique($reasons));
        }

        if ($this->nullable($issuer['gst_username'] ?? null) === null) {
            $reasons[] = 'missing_gst_username';
        }
        if ($this->nullable($issuer['gst_password'] ?? null) === null) {
            $reasons[] = 'missing_gst_password';
        }

        return array_values(array_unique($reasons));
    }

    public function forInvoice(StatutoryInvoice $invoice): ?WhitebooksCredentialSet
    {
        if ($this->missingReasons($invoice) !== []) {
            return null;
        }

        $sellerGstin = BuyerGstin::normalize($invoice->seller_gstin);
        $location = $this->sellers->locationForGstin($sellerGstin);
        if ($sellerGstin === null || $location === null) {
            return null;
        }

        $issuer = config('statutory_invoices.einvoice.issuers.'.$location, []);
        $base = $this->nullable(config('statutory_invoices.einvoice.gsp_base_url'))
            ?? 'https://api.whitebooks.in';

        return new WhitebooksCredentialSet(
            baseUrl: rtrim($base, '/'),
            gstin: $sellerGstin,
            username: (string) $this->nullable($issuer['gst_username'] ?? null),
            password: (string) $this->nullable($issuer['gst_password'] ?? null),
            clientId: (string) $this->nullable(config('statutory_invoices.einvoice.gsp_client_id')),
            clientSecret: (string) $this->nullable(config('statutory_invoices.einvoice.gsp_client_secret')),
            email: (string) $this->nullable(config('statutory_invoices.einvoice.gsp_email')),
            ipAddress: (string) $this->nullable(config('statutory_invoices.einvoice.gsp_ip_address')),
            location: $location,
        );
    }

    /**
     * WhiteBooks ip_address must be an explicit configured IPv4.
     * Do not discover, default, or substitute request/localhost/private addresses.
     */
    private function isConfiguredIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
