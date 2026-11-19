<?php

namespace App\Services\StatutoryInvoice;

final class ServiceStatutoryInvoiceMintOutboxErrorSanitizer
{
    public const GENERIC_FAILURE_MESSAGE = 'Statutory invoice processing failed; authorized support review is required.';

    /**
     * Ordered allowlist: first matching rule wins. Messages are the only strings permitted in Customer 360.
     *
     * @var list<array{needles: list<string>, message: string}>
     */
    private const ALLOWED_DIAGNOSTICS = [
        [
            'needles' => ['missing service_commerce'],
            'message' => 'Upstream commerce payload is incomplete (service commerce data missing).',
        ],
        [
            'needles' => ['no linked commerce order'],
            'message' => 'Statutory invoice mint requires a linked commerce order.',
        ],
        [
            'needles' => ['blocked until explicit per-order approval'],
            'message' => 'Automatic statutory invoice issuance is blocked until the order is explicitly approved.',
        ],
        [
            'needles' => ['automatic statutory invoice issuance', 'blocked'],
            'message' => 'Automatic statutory invoice issuance is blocked under current policy.',
        ],
        [
            'needles' => ['validation'],
            'message' => 'Invoice mint is blocked by statutory eligibility checks.',
        ],
        [
            'needles' => ['eligibility'],
            'message' => 'Invoice mint is blocked by statutory eligibility checks.',
        ],
        [
            'needles' => ['deadlock'],
            'message' => 'Temporary infrastructure issue during invoice mint.',
        ],
        [
            'needles' => ['lock wait timeout'],
            'message' => 'Temporary infrastructure issue during invoice mint.',
        ],
        [
            'needles' => ['server has gone away'],
            'message' => 'Temporary infrastructure issue during invoice mint.',
        ],
        [
            'needles' => ['too many connections'],
            'message' => 'Temporary infrastructure issue during invoice mint.',
        ],
        [
            'needles' => ['connection'],
            'message' => 'Temporary infrastructure issue during invoice mint.',
        ],
    ];

    public function sanitize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);

        foreach (self::ALLOWED_DIAGNOSTICS as $rule) {
            if ($this->matchesAllNeedles($normalized, $rule['needles'])) {
                return $rule['message'];
            }
        }

        return self::GENERIC_FAILURE_MESSAGE;
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAllNeedles(string $normalized, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (! str_contains($normalized, strtolower($needle))) {
                return false;
            }
        }

        return true;
    }
}
