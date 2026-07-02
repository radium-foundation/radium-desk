<?php

namespace App\Data;

readonly class NotificationDispatchResult
{
    /**
     * @param  array<int, NotificationResult>  $results
     */
    public function __construct(
        public bool $success,
        public array $results,
        public ?string $message = null,
    ) {}

    /**
     * @param  array<int, NotificationResult>  $results
     */
    public static function fromResults(array $results, ?string $noChannelsMessage = null): self
    {
        if ($results === []) {
            return new self(
                success: false,
                results: [],
                message: $noChannelsMessage ?? 'No notification channels are available.',
            );
        }

        $successful = array_values(array_filter(
            $results,
            fn (NotificationResult $result): bool => $result->countsTowardSuccess(),
        ));

        if ($successful !== []) {
            return new self(
                success: true,
                results: $results,
                message: $successful[0]->message,
            );
        }

        $failed = array_values(array_filter(
            $results,
            fn (NotificationResult $result): bool => ! $result->isSkipped(),
        ));

        $message = $failed[0]->message ?? $results[0]->message ?? 'Notification dispatch failed.';

        return new self(
            success: false,
            results: $results,
            message: $message,
        );
    }
}
