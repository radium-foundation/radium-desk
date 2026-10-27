<?php

namespace App\Services\Release;

use InvalidArgumentException;

/**
 * Regression Integration Policy (RIP) checkpoint ID rules for Radium Desk.
 *
 * Format: RIP-DDMMYY-HHMM-radium-desk-<short-change>-<commit7>
 * Clock: Asia/Kolkata, 24-hour, no colon in time segment.
 */
class RipCheckpointIdPolicy
{
    public const PROJECT_KEY = 'radium-desk';

    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * @return array{checkpoint_id: string, short_sha: string, rip_stamp: string, change: string}
     */
    public function compose(string $changeSlug, string $gitSha, ?\DateTimeInterface $at = null): array
    {
        $this->assertChangeSlug($changeSlug);

        $shortSha = strtolower(substr(trim($gitSha), 0, 7));

        if (! preg_match('/^[0-9a-f]{7}$/', $shortSha)) {
            throw new InvalidArgumentException('Git SHA must resolve to a 7-character hex commit suffix.');
        }

        $at ??= now(self::TIMEZONE);
        // PHP date letters: d=day, m=month, y=2-digit year → DDMMYY-HHMM (Asia/Kolkata).
        $ripStamp = $at->format('dmy-Hi');

        $checkpointId = sprintf(
            'RIP-%s-%s-%s-%s',
            $ripStamp,
            self::PROJECT_KEY,
            $changeSlug,
            $shortSha,
        );

        $this->assertCheckpointId($checkpointId, $shortSha);

        return [
            'checkpoint_id' => $checkpointId,
            'short_sha' => $shortSha,
            'rip_stamp' => $ripStamp,
            'change' => $changeSlug,
        ];
    }

    public function assertCheckpointId(string $checkpointId, ?string $expectedShortSha = null): void
    {
        $pattern = '/^RIP-\d{6}-\d{4}-'.self::PROJECT_KEY.'-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?-[0-9a-f]{7}$/';

        if (! preg_match($pattern, $checkpointId)) {
            throw new InvalidArgumentException("Invalid RIP checkpoint_id for Radium Desk: {$checkpointId}");
        }

        if ($expectedShortSha !== null) {
            $suffix = substr($checkpointId, -7);

            if (strtolower($suffix) !== strtolower($expectedShortSha)) {
                throw new InvalidArgumentException('Checkpoint ID commit suffix does not match expected SHA.');
            }
        }
    }

    public function assertChangeSlug(string $changeSlug): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $changeSlug)) {
            throw new InvalidArgumentException('Change slug must be lowercase kebab-case (a-z0-9 and hyphens).');
        }
    }
}
