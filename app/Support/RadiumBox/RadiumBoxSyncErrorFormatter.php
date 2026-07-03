<?php

namespace App\Support\RadiumBox;

class RadiumBoxSyncErrorFormatter
{
    public function friendlyMessage(
        ?string $rawError,
        ?string $errorType = null,
        ?array $metadata = null,
    ): ?string {
        $errorType ??= is_array($metadata) ? ($metadata['error_type'] ?? null) : null;
        $lookupResult = is_array($metadata) ? ($metadata['lookup_result'] ?? null) : null;

        if ($errorType === 'order_not_found' || $lookupResult === 'order_not_found') {
            return 'Order not yet available in RadiumBox';
        }

        if ($this->isRateLimited($rawError, $errorType)) {
            return 'RadiumBox rate limit reached. Please retry shortly.';
        }

        if ($this->isTimeout($rawError, $errorType)) {
            return 'RadiumBox did not respond.';
        }

        if ($this->isDuplicateSerial($rawError, $metadata)) {
            return 'Serial already exists on another order.';
        }

        if ($rawError === null || trim($rawError) === '') {
            return null;
        }

        return 'Synchronization failed.';
    }

    private function isRateLimited(?string $rawError, ?string $errorType): bool
    {
        if ($errorType === 'rate_limited') {
            return true;
        }

        if ($rawError === null) {
            return false;
        }

        $normalized = strtolower($rawError);

        return str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'too many attempts')
            || str_contains($normalized, 'too many requests')
            || str_contains($normalized, 'http 429');
    }

    private function isTimeout(?string $rawError, ?string $errorType): bool
    {
        if (in_array($errorType, ['connection_error', 'request_error'], true)) {
            return true;
        }

        if ($rawError === null) {
            return false;
        }

        $normalized = strtolower($rawError);

        return str_contains($normalized, 'timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'did not respond')
            || str_contains($normalized, 'connection');
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function isDuplicateSerial(?string $rawError, ?array $metadata): bool
    {
        if (is_array($metadata) && ($metadata['duplicate_serial'] ?? false) === true) {
            return true;
        }

        if ($rawError === null) {
            return false;
        }

        $normalized = strtolower($rawError);

        return str_contains($normalized, 'duplicate serial')
            || str_contains($normalized, 'belongs to a different order')
            || str_contains($normalized, 'serial already exists');
    }
}
