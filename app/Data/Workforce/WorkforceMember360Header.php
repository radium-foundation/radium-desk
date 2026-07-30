<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360Header
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $initials,
        public ?string $roleLabel,
        public bool $isActive,
        public string $employmentStatusLabel,
        public ?string $teamLabel,
        public ?string $joiningDateLabel,
        public bool $hasPhoto,
    ) {}
}
