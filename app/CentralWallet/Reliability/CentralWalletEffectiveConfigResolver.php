<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletEffectiveConfigResolver
{
    /**
     * @param  array<string, string|null>  $envOverrides
     * @return array<string, mixed>
     */
    public function loadConfigArray(string $configFilePath, array $envOverrides = []): array
    {
        if (! is_file($configFilePath)) {
            throw new \InvalidArgumentException('Config file missing: '.$configFilePath);
        }

        $keys = array_keys($envOverrides);
        $backup = $this->snapshotEnv($keys);

        try {
            foreach ($envOverrides as $key => $value) {
                $stringValue = $value === null ? '' : (string) $value;
                putenv($key.'='.$stringValue);
                $_ENV[$key] = $stringValue;
                $_SERVER[$key] = $stringValue;
            }

            /** @var mixed $config */
            $config = require $configFilePath;

            return is_array($config) ? $config : [];
        } finally {
            $this->restoreEnv($backup);
        }
    }

    public function dotGet(array $config, string $path): mixed
    {
        $current = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public function pathExists(array $config, string $path): bool
    {
        $current = $config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array{value: string|false, isset_env: bool, isset_server: bool}>
     */
    private function snapshotEnv(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = [
                'value' => getenv($key),
                'isset_env' => array_key_exists($key, $_ENV),
                'isset_server' => array_key_exists($key, $_SERVER),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, array{value: string|false, isset_env: bool, isset_server: bool}>  $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $state) {
            if ($state['isset_env']) {
                $_ENV[$key] = $state['value'] === false ? '' : $state['value'];
            } else {
                unset($_ENV[$key]);
            }

            if ($state['isset_server']) {
                $_SERVER[$key] = $state['value'] === false ? '' : $state['value'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($state['value'] === false) {
                putenv($key);
            } else {
                putenv($key.'='.$state['value']);
            }
        }
    }
}
