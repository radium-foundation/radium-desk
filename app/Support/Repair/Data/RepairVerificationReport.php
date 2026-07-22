<?php

namespace App\Support\Repair\Data;

readonly class RepairVerificationReport
{
    /**
     * @param  list<array{subject_key: string, ok: bool, message: string}>  $items
     */
    public function __construct(
        public bool $ok,
        public int $checked,
        public int $passed,
        public int $failed,
        public array $items = [],
        public ?string $summary = null,
    ) {}
}
