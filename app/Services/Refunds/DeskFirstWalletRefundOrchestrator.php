<?php

namespace App\Services\Refunds;

use App\CentralWallet\Application\CentralWalletRefundLedgerCreditService;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use App\Services\RdService\RdServiceInWalletRefundClient;
use App\Services\RdService\RdServiceNetWalletRefundClient;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Desk-first wallet refund: authoritative Central Wallet credit on Desk, then spoke audit.
 */
final class DeskFirstWalletRefundOrchestrator
{
    public function __construct(
        private readonly CentralWalletRefundLedgerCreditService $deskCredits,
        private readonly RdServiceInWalletRefundClient $rdServiceInWalletRefundClient,
        private readonly RdServiceNetWalletRefundClient $rdServiceNetWalletRefundClient,
        private readonly RadiumBoxWalletRefundClient $radiumBoxWalletRefundClient,
        private readonly WalletRefundDestinationResolver $destinations,
    ) {}

    public function isEnabled(): bool
    {
        return $this->deskCredits->isEnabled();
    }

    /**
     * @param  array{remarks?: string|null}  $payload
     * @return array{provider: string, reference_number: string, transaction_id: string, remarks: string, metadata: array<string, mixed>}
     */
    public function execute(RefundRequest $refund, User $actor, array $payload, string $orderId, string $amount): array
    {
        DeskFirstWalletRefundSpokePolicy::assertSpokeAllowedForDeskFirst($orderId, $this->destinations);

        $correlationId = (string) Str::uuid();

        try {
            $deskCredit = $this->deskCredits->creditForRefund($refund, $correlationId);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'refund' => 'Central Wallet credit could not be posted: '.$exception->getMessage(),
            ]);
        }

        $ledgerEntryId = (int) $deskCredit['ledger_entry_id'];
        $walletReference = (string) $deskCredit['wallet_reference'];

        try {
            $spoke = $this->recordSpokeAudit($refund, $orderId, $amount, $ledgerEntryId, $walletReference);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $remarks = trim((string) ($payload['remarks'] ?? ''));
        $defaultRemark = 'Central Wallet credited on Desk; spoke audit recorded.';

        return [
            'provider' => (string) ($spoke['provider'] ?? 'desk_first_central_wallet'),
            'reference_number' => $walletReference,
            'transaction_id' => (string) $ledgerEntryId,
            'remarks' => $remarks !== '' ? $remarks : $defaultRemark,
            'metadata' => array_merge([
                'method' => $refund->approved_refund_method?->value,
                'executed_by' => $actor->id,
                'desk_refund_reference' => $refund->reference_no,
                'destination' => 'central_wallet',
                'desk_first' => true,
                'central_wallet_ledger_entry_id' => $ledgerEntryId,
                'central_wallet_id' => $deskCredit['central_wallet_id'],
                'wallet_balance' => $deskCredit['available_balance'],
                'desk_ledger_idempotent_replay' => $deskCredit['idempotent_replay'],
                'spoke_audit' => $spoke,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSpokeAudit(
        RefundRequest $refund,
        string $orderId,
        string $amount,
        int $ledgerEntryId,
        string $walletReference,
    ): array {
        $email = $refund->order?->customer_email;

        if ($this->destinations->isRdServiceIn($orderId)) {
            if (! $this->rdServiceInWalletRefundClient->isConfigured()) {
                throw ValidationException::withMessages([
                    'refund' => 'rdservice.in wallet refund audit is not configured. Refund cannot complete after Desk credit.',
                ]);
            }

            $credit = $this->rdServiceInWalletRefundClient->creditWalletRefund(
                deskRefundReference: (string) $refund->reference_no,
                orderId: $orderId,
                amount: $amount,
                customerEmail: $email,
                centralWalletLedgerEntryId: $ledgerEntryId,
            );

            return ['provider' => 'rdservice_in_wallet_audit', ...$credit];
        }

        if ($this->destinations->isRdServiceNet($orderId)) {
            if (! $this->rdServiceNetWalletRefundClient->isConfigured()) {
                throw ValidationException::withMessages([
                    'refund' => 'rdservice.net wallet refund audit is not configured. Refund cannot complete after Desk credit.',
                ]);
            }

            $credit = $this->rdServiceNetWalletRefundClient->creditWalletRefund(
                deskRefundReference: (string) $refund->reference_no,
                orderId: $orderId,
                amount: $amount,
                customerEmail: $email,
                centralWalletLedgerEntryId: $ledgerEntryId,
            );

            return ['provider' => 'rdservice_net_central_wallet_audit', ...$credit];
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            if (! $this->radiumBoxWalletRefundClient->isConfigured()) {
                throw ValidationException::withMessages([
                    'refund' => 'RadiumBox wallet refund audit is not configured. Refund cannot complete after Desk credit.',
                ]);
            }

            $credit = $this->radiumBoxWalletRefundClient->creditWalletRefund(
                deskRefundReference: (string) $refund->reference_no,
                orderId: $orderId,
                amount: (float) $amount,
                customerEmail: $email,
                centralWalletLedgerEntryId: $ledgerEntryId,
            );

            return ['provider' => 'radiumbox_wallet_audit', ...$credit];
        }

        throw ValidationException::withMessages([
            'refund' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
        ]);
    }
}
