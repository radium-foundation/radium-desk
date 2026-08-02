<?php

namespace App\Data\Platform;

readonly class PlatformZoneExpandResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $zone,
        public string $item,
        public string $html,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'zone' => $this->zone,
            'item' => $this->item,
            'html' => $this->html,
            'meta' => $this->meta,
        ];
    }
}
