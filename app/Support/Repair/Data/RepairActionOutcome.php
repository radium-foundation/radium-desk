<?php

namespace App\Support\Repair\Data;

use App\Support\Repair\Enums\RepairItemOutcome;
use Throwable;

readonly class RepairActionOutcome
{
    /**
     * @param  list<string>  $messages
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $afterSnapshot
     */
    public function __construct(
        public RepairItemOutcome $outcome,
        public string $action,
        public string $category,
        public array $messages = [],
        public array $meta = [],
        public ?array $afterSnapshot = null,
        public ?string $skipReason = null,
        public ?string $errorMessage = null,
        public ?Throwable $exception = null,
    ) {}

    public static function would(
        RepairItemOutcome $outcome,
        string $action,
        string $category,
        array $messages = [],
        ?string $skipReason = null,
    ): self {
        return new self(
            outcome: $outcome,
            action: $action,
            category: $category,
            messages: $messages,
            skipReason: $skipReason,
        );
    }

    public static function success(
        RepairItemOutcome $outcome,
        string $action,
        string $category,
        array $messages = [],
        ?array $afterSnapshot = null,
        array $meta = [],
    ): self {
        return new self(
            outcome: $outcome,
            action: $action,
            category: $category,
            messages: $messages,
            meta: $meta,
            afterSnapshot: $afterSnapshot,
        );
    }

    public static function skipped(
        string $action,
        string $category,
        string $reason,
        array $messages = [],
    ): self {
        return new self(
            outcome: RepairItemOutcome::Skipped,
            action: $action,
            category: $category,
            messages: $messages,
            skipReason: $reason,
        );
    }

    public static function failed(
        string $action,
        string $category,
        string $errorMessage,
        ?Throwable $exception = null,
    ): self {
        return new self(
            outcome: RepairItemOutcome::Failed,
            action: $action,
            category: $category,
            errorMessage: $errorMessage,
            exception: $exception,
        );
    }

    public function isFailure(): bool
    {
        return $this->outcome === RepairItemOutcome::Failed;
    }

    public function isSuccessMutation(): bool
    {
        return in_array($this->outcome, [
            RepairItemOutcome::Repaired,
            RepairItemOutcome::CleanedUp,
        ], true);
    }
}
