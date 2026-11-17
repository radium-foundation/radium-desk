<?php

namespace App\Services\HardwareFulfilment;

final class CarrierDocumentUrlValidator
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function __construct(
        private readonly array $allowedHosts,
    ) {}

    public static function fromConfig(): self
    {
        /** @var list<string> $hosts */
        $hosts = config('shipping.document_download_allowed_hosts', []);

        return new self($hosts);
    }

    public function isAllowedUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $this->isBlockedHost($host)) {
            return false;
        }

        return in_array($host, $this->allowedHosts, true);
    }

    private function isBlockedHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;

            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        return false;
    }
}
