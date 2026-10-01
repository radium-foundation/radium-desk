<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\MigrationControlledCwidProvisionService;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('central-wallet:migration-provision-cwid
    {site-code : Spoke site code e.g. radiumbox.com}
    {local-user-id : Spoke local user ID}
    {--identity-type= : trusted_google|verified_email}
    {--google-subject= : Google subject when identity-type=trusted_google}
    {--email= : Verified email when identity-type=verified_email}
    {--refund-ids= : Comma-separated refund IDs for audit evidence}
    {--dry-run : Validate only; no writes}')]
#[Description('Migration-prep: provision CWID + Desk Customer from trusted identity (no ledger writes)')]
class CentralWalletMigrationProvisionCwidCommand extends Command
{
    public function __construct(
        private readonly MigrationControlledCwidProvisionService $provisionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $siteCode = (string) $this->argument('site-code');
        $localUserId = (string) $this->argument('local-user-id');
        $identityType = (string) ($this->option('identity-type') ?? '');
        $refundIds = array_values(array_filter(array_map(
            static fn (string $id): string => trim($id),
            explode(',', (string) ($this->option('refund-ids') ?? '')),
        )));

        $identity = $this->buildIdentityPayload($identityType);

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'dry_run' => true,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'identity_type' => $identityType,
                'refund_ids' => $refundIds,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        try {
            $result = $this->provisionService->provision(
                siteCode: $siteCode,
                localUserId: $localUserId,
                identity: $identity,
                evidence: [
                    'refund_ids' => $refundIds,
                    'prompt' => 'RadiumDesk-P-30-09-29',
                ],
                actorId: 'cli:migration-provision-cwid',
                correlationId: (string) Str::uuid(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIdentityPayload(string $identityType): array
    {
        if ($identityType === 'trusted_google' || $identityType === CustomerIdentityCredentialType::Google->value) {
            $subject = trim((string) ($this->option('google-subject') ?? ''));
            if ($subject === '') {
                throw new InvalidArgumentException('google_subject_required');
            }

            return [
                'type' => CustomerIdentityCredentialType::Google->value,
                'google_subject' => $subject,
            ];
        }

        if ($identityType === 'verified_email' || $identityType === CustomerIdentityCredentialType::VerifiedEmail->value) {
            $email = trim((string) ($this->option('email') ?? ''));
            if ($email === '') {
                throw new InvalidArgumentException('verified_email_required');
            }

            return [
                'type' => CustomerIdentityCredentialType::VerifiedEmail->value,
                'email' => $email,
            ];
        }

        throw new InvalidArgumentException('unsupported_identity_type');
    }
}
