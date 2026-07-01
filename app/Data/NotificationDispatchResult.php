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
            fn (NotificationResult $result): bool => $result->success,
        ));

        if ($successful !== []) {
            return new self(
                success: true,
                results: $results,
                message: $successful[0]->message,
            );
        }

        $failed = $results[0];

        return new self(
            success: false,
            results: $results,
            message: $failed->message,
        );
    }
}
