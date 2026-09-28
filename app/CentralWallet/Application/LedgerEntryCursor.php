<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class LedgerEntryCursor
{
    private const VERSION = 1;

    public function __construct(
        public readonly CarbonImmutable $postedAt,
        public readonly int $id,
    ) {}

    public static function fromEntry(CentralWalletLedgerEntry $entry): self
    {
        return new self(
            CarbonImmutable::parse($entry->posted_at->format('Y-m-d H:i:s')),
            $entry->id,
        );
    }

    public static function decode(string $opaque): self
    {
        $json = base64_decode($opaque, true);
        if ($json === false) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        if (! is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || ! is_string($data['posted_at'] ?? null)
            || ! is_int($data['id'] ?? null)
            || $data['id'] < 1) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        try {
            $postedAt = CarbonImmutable::parse($data['posted_at']);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return new self($postedAt, $data['id']);
    }

    public function encode(): string
    {
        $payload = json_encode([
            'v' => self::VERSION,
            'posted_at' => $this->postedAt->format('Y-m-d\TH:i:s'),
            'id' => $this->id,
        ], JSON_THROW_ON_ERROR);

        return base64_encode($payload);
    }
}
