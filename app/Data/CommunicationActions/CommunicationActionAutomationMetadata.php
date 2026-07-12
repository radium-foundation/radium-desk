<?php

namespace App\Data\CommunicationActions;

readonly class CommunicationActionAutomationMetadata
{
    public function __construct(
        public bool $enabled,
        public ?string $futureTrigger,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            futureTrigger: filled($config['future_trigger'] ?? null)
                ? (string) $config['future_trigger']
                : null,
        );
    }
}
