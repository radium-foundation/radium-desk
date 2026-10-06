<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use InvalidArgumentException;

final class HistoricalContactIdentityMatchService
{
    public function __construct(
        private readonly HistoricalVisibilityContactIndexLoader $indexLoader,
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly ReconciledHistoricalRefundFilter $reconciledFilter,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
        ?string $correlationId = null,
    ): ?array {
        if (! (bool) config('central_wallet.historical_wallet_visibility.contact_match_enabled', true)) {
            return null;
        }

        $email = strtolower(trim((string) $email));
        $mobileDigits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        if ($email === '' && strlen($mobileDigits) < 10) {
            return null;
        }

        try {
            $manifest = $this->indexLoader->load();
        } catch (InvalidArgumentException) {
            return null;
        }

        $emailMatches = [];
        $mobileMatches = [];

        if ($email !== '' && str_contains($email, '@')) {
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
                $emailMatches = $this->indexLoader->findByEmailHash($manifest, $emailHash);
            } catch (InvalidArgumentException) {
                $emailMatches = [];
            }
        }

        if (strlen($mobileDigits) >= 10) {
            $mobileHash = $this->hashContactMobile($mobileDigits);
            $mobileMatches = $this->indexLoader->findByMobileHash($manifest, $mobileHash);
        }

        if ($emailMatches === [] && $mobileMatches === []) {
            return null;
        }

        if ($emailMatches !== [] && $mobileMatches !== []) {
            $intersection = $this->intersectRowsByRefundId($emailMatches, $mobileMatches);
            if ($intersection !== []) {
                $emailMatches = $intersection;
                $mobileMatches = $intersection;
            }
        }

        $emailCustomerKeys = $this->customerKeys($emailMatches);
        $mobileCustomerKeys = $this->customerKeys($mobileMatches);

        if (count($emailCustomerKeys) > 1 || count($mobileCustomerKeys) > 1) {
            return $this->ambiguous($siteCode, $localUserId, $correlationId, 'ambiguous_contact_match');
        }

        if ($emailCustomerKeys !== [] && $mobileCustomerKeys !== []
            && $emailCustomerKeys !== $mobileCustomerKeys) {
            return $this->ambiguous($siteCode, $localUserId, $correlationId, 'email_mobile_conflict');
        }

        $selected = $this->mergeUniqueRows($emailMatches, $mobileMatches);
        if ($selected === []) {
            return null;
        }

        $balance = $this->reconciledFilter->sumDisplayableAmounts($selected, 'refund_amount');
        if (bccomp($balance, '0', 2) <= 0) {
            return [
                'status' => 404,
                'body' => [
                    'error' => 'identity_unresolved',
                    'identity_state' => 'unresolved',
                    'reason' => 'historical_balance_already_settled',
                ],
            ];
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.historical_contact_match_resolved',
            centralWalletId: null,
            actorType: AuditActorType::Customer,
            actorId: 'customer:'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'refund_count' => count($selected),
            ],
        );

        return [
            'status' => 200,
            'body' => [
                'identity_state' => 'provisional',
                'verification_status' => 'unverified',
                'available_balance' => $balance,
                'balance_source' => 'historical_wallet_refund',
                'currency' => (string) config('central_wallet.currency', 'INR'),
                'verification_required' => true,
                'financial_use_requires_verification' => true,
            ],
        ];
    }

    private function hashContactMobile(string $digits): string
    {
        $normalized = strlen($digits) === 10 ? '+91'.$digits : '+'.$digits;

        return hash('sha256', 'historical_contact_mobile:'.$normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function customerKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $emailHash = (string) ($row['order_email_hash'] ?? '');
            $mobileHash = (string) ($row['order_mobile_hash'] ?? '');
            $key = $emailHash.'|'.$mobileHash;
            if ($key !== '|') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    private function intersectRowsByRefundId(array $a, array $b): array
    {
        $bByRefundId = [];
        foreach ($b as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId > 0) {
                $bByRefundId[$refundId] = $row;
            }
        }

        $intersection = [];
        foreach ($a as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId > 0 && isset($bByRefundId[$refundId])) {
                $intersection[$refundId] = $row;
            }
        }

        return array_values($intersection);
    }

    /**
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    private function mergeUniqueRows(array $a, array $b): array
    {
        $merged = [];
        foreach (array_merge($a, $b) as $row) {
            $refundId = (int) ($row['refund_id'] ?? 0);
            if ($refundId > 0) {
                $merged[$refundId] = $row;
            }
        }

        return array_values($merged);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ambiguous(
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
        string $reason,
    ): array {
        $this->auditEvents->record(
            eventType: 'customer_identity.historical_contact_ambiguous',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: 'visibility:'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'reason' => $reason,
            ],
        );

        return [
            'status' => 409,
            'body' => [
                'error' => 'identity_ambiguous',
                'identity_state' => 'unresolved',
                'reason' => $reason,
            ],
        ];
    }
}
