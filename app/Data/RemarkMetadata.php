<?php

namespace App\Data;

/**
 * Extensible note metadata for future capabilities.
 *
 * Stored as JSON on remarks.metadata. Only keys with values are persisted.
 * Search and timeline rendering intentionally ignore unimplemented keys.
 */
readonly class RemarkMetadata
{
    public const KEY_REMINDER = 'reminder';

    public const KEY_PINNED = 'pinned';

    public const KEY_CUSTOMER_NOTIFICATION = 'customer_notification';

    public const KEY_ATTACHMENTS = 'attachments';

    public const KEY_VOICE_NOTE = 'voice_note';

    public const KEY_AI_MENTIONS = 'ai_mentions';

    /**
     * @param  array<string, mixed>|null  $reminder
     * @param  array<string, mixed>|null  $pinned
     * @param  array<string, mixed>|null  $customerNotification
     * @param  list<array<string, mixed>>|null  $attachments
     * @param  array<string, mixed>|null  $voiceNote
     * @param  list<string>|null  $aiMentions
     */
    public function __construct(
        public ?array $reminder = null,
        public ?array $pinned = null,
        public ?array $customerNotification = null,
        public ?array $attachments = null,
        public ?array $voiceNote = null,
        public ?array $aiMentions = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            return new self;
        }

        return new self(
            reminder: self::arrayOrNull($data[self::KEY_REMINDER] ?? null),
            pinned: self::arrayOrNull($data[self::KEY_PINNED] ?? null),
            customerNotification: self::arrayOrNull($data[self::KEY_CUSTOMER_NOTIFICATION] ?? null),
            attachments: self::listOrNull($data[self::KEY_ATTACHMENTS] ?? null),
            voiceNote: self::arrayOrNull($data[self::KEY_VOICE_NOTE] ?? null),
            aiMentions: self::stringListOrNull($data[self::KEY_AI_MENTIONS] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            self::KEY_REMINDER => $this->reminder,
            self::KEY_PINNED => $this->pinned,
            self::KEY_CUSTOMER_NOTIFICATION => $this->customerNotification,
            self::KEY_ATTACHMENTS => $this->attachments,
            self::KEY_VOICE_NOTE => $this->voiceNote,
            self::KEY_AI_MENTIONS => $this->aiMentions,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  list<string>  $aiMentions
     */
    public function withAiMentions(array $aiMentions): self
    {
        $normalized = array_values(array_unique(array_filter(
            array_map('strval', $aiMentions),
            fn (string $mention): bool => $mention !== '',
        )));

        return new self(
            reminder: $this->reminder,
            pinned: $this->pinned,
            customerNotification: $this->customerNotification,
            attachments: $this->attachments,
            voiceNote: $this->voiceNote,
            aiMentions: $normalized === [] ? null : $normalized,
        );
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private static function arrayOrNull(?array $value): ?array
    {
        return $value === null || $value === [] ? null : $value;
    }

    /**
     * @param  list<array<string, mixed>>|null  $value
     * @return list<array<string, mixed>>|null
     */
    private static function listOrNull(?array $value): ?array
    {
        return $value === null || $value === [] ? null : array_values($value);
    }

    /**
     * @param  list<string>|null  $value
     * @return list<string>|null
     */
    private static function stringListOrNull(?array $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        $normalized = array_values(array_filter(
            array_map('strval', $value),
            fn (string $item): bool => $item !== '',
        ));

        return $normalized === [] ? null : $normalized;
    }
}
