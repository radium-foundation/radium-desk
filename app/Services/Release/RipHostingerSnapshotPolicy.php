<?php

namespace App\Services\Release;

use InvalidArgumentException;

/**
 * Optional RIP manifest hostinger_vps_recovery block (P-14-20).
 *
 * Does not imply restorability — only verified API-reported recovery-point metadata.
 */
class RipHostingerSnapshotPolicy
{
    public const PROVIDER = 'hostinger';

    public const STATUS_NOT_REQUIRED = 'not-required';

    public const STATUS_VERIFICATION_PENDING = 'verification-pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_MISSING = 'missing';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_FAILED = 'failed';

    private const ALLOWED_STATUSES = [
        self::STATUS_NOT_REQUIRED,
        self::STATUS_VERIFICATION_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_EXPIRED,
        self::STATUS_MISSING,
        self::STATUS_MISMATCH,
        self::STATUS_FAILED,
    ];

    /**
     * @param  array<string, mixed>  $block
     */
    public function validateRecoveryBlock(array $block): void
    {
        $required = [
            'schema_version',
            'provider',
            'virtual_machine_id',
            'hostname',
            'verification_status',
            'verified_at',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $block)) {
                throw new InvalidArgumentException("hostinger_vps_recovery missing key: {$key}");
            }
        }

        if ((int) $block['schema_version'] !== 1) {
            throw new InvalidArgumentException('hostinger_vps_recovery schema_version must be 1.');
        }

        if (($block['provider'] ?? '') !== self::PROVIDER) {
            throw new InvalidArgumentException('hostinger_vps_recovery provider must be hostinger.');
        }

        if ((int) $block['virtual_machine_id'] !== 1910783) {
            throw new InvalidArgumentException('hostinger_vps_recovery virtual_machine_id must be 1910783.');
        }

        $status = (string) $block['verification_status'];
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('hostinger_vps_recovery verification_status invalid.');
        }

        if (in_array($status, [self::STATUS_VERIFIED, self::STATUS_EXPIRED], true)) {
            if (empty($block['snapshot_id'])) {
                throw new InvalidArgumentException('verified/expired status requires snapshot_id.');
            }
        }

        foreach (['authorization_reference', 'api_token', 'bearer'] as $forbidden) {
            if (array_key_exists($forbidden, $block)) {
                throw new InvalidArgumentException("hostinger_vps_recovery must not contain {$forbidden}.");
            }
        }
    }
}