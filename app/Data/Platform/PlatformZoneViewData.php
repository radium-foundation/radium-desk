<?php

namespace App\Data\Platform;

readonly class PlatformZoneViewData
{
    public function __construct(
        public PlatformZoneDefinition $definition,
        public PlatformZoneSnapshot $snapshot,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'definition' => $this->definition->toArray(),
            'snapshot' => $this->snapshot->toArray(),
        ];
    }
}
