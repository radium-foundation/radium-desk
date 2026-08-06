<?php

namespace App\Data\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailImportance;

final readonly class IncomingEmailLearningApplicationResult
{
    public function __construct(
        public bool $stopProcessing,
        public bool $applied,
        public ?IncomingEmailClassification $classificationOverride = null,
        public ?IncomingEmailImportance $importanceOverride = null,
        public ?int $assigneeUserId = null,
    ) {}

    public static function none(): self
    {
        return new self(
            stopProcessing: false,
            applied: false,
        );
    }
}
