<?php

namespace App\Data\CommunicationActions;

readonly class CommunicationActionTarget
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}

    /**
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
        ];
    }
}
