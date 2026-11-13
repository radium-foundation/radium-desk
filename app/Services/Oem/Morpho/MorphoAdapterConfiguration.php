<?php

namespace App\Services\Oem\Morpho;

use App\Services\Oem\Morpho\Exceptions\MorphoLiveIntegrationDisabledException;

final class MorphoAdapterConfiguration
{
    public function __construct(
        private readonly string $driver,
        private readonly bool $liveEnabled,
        private readonly string $validatorBaseUrl,
        private readonly string $intermediateBaseUrl,
        private readonly string $authorization,
        private readonly string $securityStamp,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly int $retryTimes,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            driver: (string) config('morpho_otbs_adapter.driver', 'mock'),
            liveEnabled: (bool) config('morpho_otbs_adapter.live.enabled', false),
            validatorBaseUrl: trim((string) config('morpho_otbs_adapter.live.validator_base_url', '')),
            intermediateBaseUrl: trim((string) config('morpho_otbs_adapter.live.intermediate_base_url', '')),
            authorization: trim((string) config('morpho_otbs_adapter.live.authorization', '')),
            securityStamp: trim((string) config('morpho_otbs_adapter.live.security_stamp', '')),
            timeoutSeconds: (int) config('morpho_otbs_adapter.http.timeout_seconds', 10),
            connectTimeoutSeconds: (int) config('morpho_otbs_adapter.http.connect_timeout_seconds', 5),
            retryTimes: (int) config('morpho_otbs_adapter.http.retry_times', 1),
        );
    }

    public function usesMockDriver(): bool
    {
        return $this->driver !== 'live';
    }

    public function assertLiveIntegrationAllowed(): void
    {
        if ($this->usesMockDriver()) {
            throw new MorphoLiveIntegrationDisabledException(
                'Morpho live integration is disabled; driver is not live.',
            );
        }

        if (! $this->liveEnabled) {
            throw new MorphoLiveIntegrationDisabledException(
                'Morpho live integration is not enabled.',
            );
        }

        if ($this->authorization === '' || $this->securityStamp === '') {
            throw new MorphoLiveIntegrationDisabledException(
                'Morpho live credentials are not configured.',
            );
        }

        if ($this->validatorBaseUrl === '' || $this->intermediateBaseUrl === '') {
            throw new MorphoLiveIntegrationDisabledException(
                'Morpho live base URLs are not configured.',
            );
        }
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->connectTimeoutSeconds;
    }

    public function retryTimes(): int
    {
        return $this->retryTimes;
    }
}
