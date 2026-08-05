<?php

namespace App\Data;

readonly class TeamActivityPerformanceBadge
{
    public function __construct(
        public string $key,
        public string $emoji,
        public string $title,
        public string $tooltip,
    ) {}

    /**
     * @return array{key: string, emoji: string, title: string, tooltip: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'emoji' => $this->emoji,
            'title' => $this->title,
            'tooltip' => $this->tooltip,
        ];
    }
}
