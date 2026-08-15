<?php

namespace App\Infrastructure\DatabaseSync;

use InvalidArgumentException;

final readonly class RemoteEndpointProfile
{
    public function __construct(
        public string $name,
        public string $label,
        public string $sshHost,
        public int $sshPort,
        public string $sshUser,
        public string $projectPath,
        public string $phpBin,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $role, array $config): self
    {
        $name = self::requireString($config, 'name', $role);
        $label = self::requireString($config, 'label', $role);
        $sshHost = self::requireString($config, 'ssh_host', $role);
        $sshUser = self::requireString($config, 'ssh_user', $role);
        $projectPath = self::requireString($config, 'project_path', $role);
        $phpBin = self::requireString($config, 'php_bin', $role);
        $sshPort = $config['ssh_port'] ?? null;

        if (! is_int($sshPort) || $sshPort < 1 || $sshPort > 65535) {
            throw new InvalidArgumentException("Database sync {$role} profile must define a valid ssh_port.");
        }

        return new self(
            name: $name,
            label: $label,
            sshHost: $sshHost,
            sshPort: $sshPort,
            sshUser: $sshUser,
            projectPath: $projectPath,
            phpBin: $phpBin,
        );
    }

    public function sshTarget(): string
    {
        return sprintf('%s@%s', $this->sshUser, $this->sshHost);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function requireString(array $config, string $key, string $role): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Database sync {$role} profile is missing [{$key}].");
        }

        return trim($value);
    }
}
