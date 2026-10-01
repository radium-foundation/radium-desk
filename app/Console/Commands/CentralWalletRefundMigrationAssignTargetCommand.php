<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\RefundMigrationTargetAssignmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('central-wallet:refund-migration-assign-target
    {refund-id : Refund request ID}
    {--desk-customer-id= : Desk Customer UUID}
    {--cwid= : Central Wallet UUID}
    {--owner-approval-ref= : Owner approval reference}
    {--resolution-type= : ambiguous_spoke_target or identity_assignment}
    {--source-application= : Resolved spoke site for ambiguous rows}
    {--source-wallet-id= : Resolved spoke wallet ID}
    {--source-local-user-id= : Resolved spoke local user ID}
    {--approved-by=console}')]
#[Description('Assign explicit Desk Customer / CWID target for a refund migration journal row')]
class CentralWalletRefundMigrationAssignTargetCommand extends Command
{
    public function __construct(
        private readonly RefundMigrationTargetAssignmentService $assignmentService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $refundId = (int) $this->argument('refund-id');
        $deskCustomerId = (string) $this->option('desk-customer-id');
        $cwid = (string) $this->option('cwid');
        $ownerApprovalRef = (string) $this->option('owner-approval-ref');
        $resolutionType = $this->option('resolution-type');

        if ($deskCustomerId === '' || $cwid === '' || $ownerApprovalRef === '') {
            $this->error('--desk-customer-id, --cwid, and --owner-approval-ref are required.');

            return self::FAILURE;
        }

        try {
            if (is_string($resolutionType) && $resolutionType !== '') {
                $migration = $this->assignmentService->assignOwnerResolution($refundId, [
                    'resolution_type' => $resolutionType,
                    'source_application' => $this->option('source-application'),
                    'source_wallet_id' => $this->option('source-wallet-id') !== null
                        ? (int) $this->option('source-wallet-id')
                        : null,
                    'source_local_user_id' => $this->option('source-local-user-id'),
                    'desk_customer_id' => $deskCustomerId,
                    'cwid' => $cwid,
                    'owner_approval_ref' => $ownerApprovalRef,
                    'approved_by' => (string) $this->option('approved-by'),
                ]);
            } else {
                $migration = $this->assignmentService->assignDirectTarget(
                    $refundId,
                    $deskCustomerId,
                    $cwid,
                    $ownerApprovalRef,
                    (string) $this->option('approved-by'),
                );
            }

            $this->info('refund_id='.$migration->refund_id.' status='.$migration->status->value);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
