<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Binds a Cashfree-created Desk order to exactly one Central Customer and wallet
 * using exact normalized email identity. Does not create refund ledger entries.
 */
final class CashfreeCentralCustomerBinder
{
    public const PROVIDER_CASHFREE_ORDER_EMAIL = 'cashfree_order_email';

    public const PROVIDER_DESK_EMAIL = 'desk_email';

    public const ACTOR_ID = 'cashfree_central_customer_binder';

    public const STATUS_ALREADY_BOUND = 'already_bound';

    public const STATUS_BOUND = 'bound';

    public const STATUS_BOUND_REUSED = 'bound_reused';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_INVALID_EMAIL = 'invalid_email';

    public const STATUS_WALLET_OWNER_CONFLICT = 'wallet_owner_conflict';

    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly CentralWalletService $wallets,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * Idempotent customer bind for a paid Cashfree order inside the caller's transaction.
     */
    public function bindOrder(Order $order, ?string $cfPaymentId = null, ?string $correlationId = null): string
    {
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->first() ?? $order;

        $existingCustomerId = trim((string) ($order->customer_id ?? ''));
        if ($existingCustomerId !== '' && Str::isUuid($existingCustomerId)) {
            if ($this->resolveCustomerWallet($existingCustomerId) !== null) {
                return self::STATUS_ALREADY_BOUND;
            }

            $this->recordBindEvent(
                status: self::STATUS_WALLET_OWNER_CONFLICT,
                order: $order,
                cfPaymentId: $cfPaymentId,
                correlationId: $correlationId,
                deskCustomerId: $existingCustomerId,
            );

            return self::STATUS_WALLET_OWNER_CONFLICT;
        }

        $email = strtolower(trim((string) ($order->customer_email ?? '')));
        try {
            $subjectHash = $this->subjectHasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            $this->recordBindEvent(
                status: self::STATUS_INVALID_EMAIL,
                order: $order,
                cfPaymentId: $cfPaymentId,
                correlationId: $correlationId,
            );

            return self::STATUS_INVALID_EMAIL;
        }

        $customerIds = $this->deskCustomerIdsForEmailHash($subjectHash);
        if ($customerIds->count() > 1) {
            $this->recordBindEvent(
                status: self::STATUS_AMBIGUOUS,
                order: $order,
                cfPaymentId: $cfPaymentId,
                correlationId: $correlationId,
                extra: ['matching_customer_count' => $customerIds->count()],
            );

            return self::STATUS_AMBIGUOUS;
        }

        if ($customerIds->count() === 1) {
            $deskCustomerId = (string) $customerIds->first();
            if ($this->resolveCustomerWallet($deskCustomerId) === null) {
                $this->recordBindEvent(
                    status: self::STATUS_WALLET_OWNER_CONFLICT,
                    order: $order,
                    cfPaymentId: $cfPaymentId,
                    correlationId: $correlationId,
                    deskCustomerId: $deskCustomerId,
                );

                return self::STATUS_WALLET_OWNER_CONFLICT;
            }

            $order->update(['customer_id' => $deskCustomerId]);
            $this->recordBindEvent(
                status: self::STATUS_BOUND_REUSED,
                order: $order,
                cfPaymentId: $cfPaymentId,
                correlationId: $correlationId,
                deskCustomerId: $deskCustomerId,
            );

            return self::STATUS_BOUND_REUSED;
        }

        try {
            $deskCustomerId = $this->provisionCustomerWithCashfreeEmail(
                $subjectHash,
                $order,
                $cfPaymentId,
                $correlationId,
            );
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'credential_conflict') {
                $customerIds = $this->deskCustomerIdsForEmailHash($subjectHash);
                if ($customerIds->count() === 1) {
                    $deskCustomerId = (string) $customerIds->first();
                    if ($this->resolveCustomerWallet($deskCustomerId) !== null) {
                        $order->update(['customer_id' => $deskCustomerId]);
                        $this->recordBindEvent(
                            status: self::STATUS_BOUND_REUSED,
                            order: $order,
                            cfPaymentId: $cfPaymentId,
                            correlationId: $correlationId,
                            deskCustomerId: $deskCustomerId,
                        );

                        return self::STATUS_BOUND_REUSED;
                    }
                }

                $this->recordBindEvent(
                    status: self::STATUS_AMBIGUOUS,
                    order: $order,
                    cfPaymentId: $cfPaymentId,
                    correlationId: $correlationId,
                    extra: ['reason' => 'credential_conflict'],
                );

                return self::STATUS_AMBIGUOUS;
            }

            throw $exception;
        }

        $order->update(['customer_id' => $deskCustomerId]);
        $this->recordBindEvent(
            status: self::STATUS_BOUND,
            order: $order,
            cfPaymentId: $cfPaymentId,
            correlationId: $correlationId,
            deskCustomerId: $deskCustomerId,
        );

        return self::STATUS_BOUND;
    }

    /**
     * @return array{state: 'resolved', customer_id: string, central_wallet_id: string, wallet_status: string}|null
     */
    public function resolveCustomerWallet(string $deskCustomerId): ?array
    {
        $customer = CentralCustomer::query()->find($deskCustomerId);
        if ($customer === null || trim((string) $customer->central_wallet_id) === '') {
            return null;
        }

        $centralWalletId = (string) $customer->central_wallet_id;
        $ownerIds = CentralCustomer::query()
            ->where('central_wallet_id', $centralWalletId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id);

        if ($ownerIds->count() !== 1 || $ownerIds->first() !== (string) $customer->id) {
            return null;
        }

        $wallet = $this->wallets->find($centralWalletId);
        if ($wallet === null) {
            return null;
        }

        return [
            'state' => 'resolved',
            'customer_id' => (string) $customer->id,
            'central_wallet_id' => $centralWalletId,
            'wallet_status' => (string) $wallet->status,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function deskCustomerIdsForEmailHash(string $subjectHash): Collection
    {
        return CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->whereIn('provider', [self::PROVIDER_DESK_EMAIL, self::PROVIDER_CASHFREE_ORDER_EMAIL])
            ->where('subject_hash', $subjectHash)
            ->whereNotNull('verified_at')
            ->pluck('desk_customer_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();
    }

    private function provisionCustomerWithCashfreeEmail(
        string $subjectHash,
        Order $order,
        ?string $cfPaymentId,
        ?string $correlationId,
    ): string {
        return DB::transaction(function () use ($subjectHash, $order, $cfPaymentId, $correlationId): string {
            $existing = $this->deskCustomerIdsForEmailHash($subjectHash);
            if ($existing->count() === 1) {
                return (string) $existing->first();
            }
            if ($existing->count() > 1) {
                throw new InvalidArgumentException('ambiguous_email_identity');
            }

            $wallet = $this->wallets->create($correlationId);
            $customerId = (string) Str::uuid();

            CentralCustomer::query()->create([
                'id' => $customerId,
                'central_wallet_id' => $wallet->id,
                'status' => 'active',
            ]);

            try {
                CentralCustomerIdentityCredential::query()->create([
                    'desk_customer_id' => $customerId,
                    'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
                    'provider' => self::PROVIDER_CASHFREE_ORDER_EMAIL,
                    'subject_hash' => $subjectHash,
                    'verified_at' => now(),
                    'metadata' => [
                        'source' => 'cashfree_webhook',
                        'order_id' => $order->order_id,
                        'cf_payment_id' => $cfPaymentId,
                    ],
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'central_customer_credentials_subject_uq')) {
                    throw new InvalidArgumentException('credential_conflict');
                }

                throw $exception;
            }

            return $customerId;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordBindEvent(
        string $status,
        Order $order,
        ?string $cfPaymentId,
        ?string $correlationId,
        ?string $deskCustomerId = null,
        array $extra = [],
    ): void {
        $centralWalletId = null;
        if ($deskCustomerId !== null) {
            $centralWalletId = CentralCustomer::query()
                ->where('id', $deskCustomerId)
                ->value('central_wallet_id');
            $centralWalletId = is_string($centralWalletId) ? $centralWalletId : null;
        }

        $this->auditEvents->record(
            eventType: 'cashfree.customer_bind.'.$status,
            centralWalletId: $centralWalletId,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID,
            correlationId: $correlationId,
            payload: array_merge([
                'status' => $status,
                'desk_order_record_id' => $order->id,
                'business_order_id' => $order->order_id,
                'cf_payment_id' => $cfPaymentId,
                'desk_customer_id' => $deskCustomerId,
            ], $extra),
        );
    }
}
