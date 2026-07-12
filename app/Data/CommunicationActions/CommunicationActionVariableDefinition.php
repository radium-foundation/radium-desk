<?php

namespace App\Data\CommunicationActions;

readonly class CommunicationActionVariableDefinition
{
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public bool $required = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            type: (string) ($config['type'] ?? 'text'),
            label: (string) ($config['label'] ?? $key),
            required: (bool) ($config['required'] ?? false),
        );
    }
}
