<?php

namespace App\Data\Operations;

readonly class IraBriefingFormatted
{
    /**
     * @param  list<string>  $operationsLines
     * @param  list<string>  $teamLines
     * @param  list<string>  $attentionLines
     */
    public function __construct(
        public string $greeting,
        public array $operationsLines,
        public bool $teamPresenceCollecting,
        public array $teamLines,
        public array $attentionLines,
        public ?string $suggestion,
        public string $telegramMessage,
        public int $criticalRiskCount = 0,
        public int $attentionRiskCount = 0,
        public int $monitoringRiskCount = 0,
    ) {}
}
