<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReservationService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ReservationStateMachine $stateMachine,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    public function create(
        string $centralWalletId,
        string $callerId,
        string $amount,
        string $businessReference,
        string $correlationId,
        ?string $sourceReference = null,
        array $metadata = [],
    ): CentralWalletReservation {
        Cwid::fromString($centralWalletId);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Reservation amount must be positive.');
        }

        $businessReference = trim($businessReference);
        if ($businessReference === '') {
            throw new InvalidArgumentException('business_reference is required.');
        }

        $currency = (string) config('central_wallet.currency', 'INR');
        $ttlSeconds = max(60, (int) config('central_wallet.reservation_ttl_seconds', 900));

        try {
            return DB::transaction(function () use (
                $centralWalletId,
                $callerId,
                $amount,
                $businessReference,
                $correlationId,
                $sourceReference,
                $metadata,
                $currency,
                $ttlSeconds,
            ): CentralWalletReservation {
                $wallet = CentralWallet::query()->lockForUpdate()->find($centralWalletId);
                if ($wallet === null) {
                    throw new InvalidArgumentException('Central wallet not found.');
                }

                $spendable = $this->ledger->spendableBalance($centralWalletId);
                if (bccomp($spendable, $amount, 2) < 0) {
                    throw new InvalidArgumentException('Insufficient available balance.');
                }

                $reservation = CentralWalletReservation::query()->create([
                    'id' => (string) Str::uuid(),
                    'central_wallet_id' => $centralWalletId,
                    'caller_id' => $callerId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'state' => ReservationState::Active,
                    'business_reference' => $businessReference,
                    'correlation_id' => $correlationId,
                    'source_reference' => $sourceReference,
                    'metadata' => $metadata === [] ? null : $metadata,
                    'expires_at' => now()->addSeconds($ttlSeconds),
                ]);

                $this->auditEvents->record(
                    eventType: 'reservation.created',
                    centralWalletId: $centralWalletId,
                    actorType: AuditActorType::Service,
                    actorId: $callerId,
                    correlationId: $correlationId,
                    payload: [
                        'reservation_id' => $reservation->id,
                        'amount' => $amount,
                        'currency' => $currency,
                        'business_reference' => $businessReference,
                        'expires_at' => $reservation->expires_at?->toIso8601String(),
                    ],
                );

                return $reservation;
            });
        } catch (InvalidArgumentException $exception) {
            if (str_contains($exception->getMessage(), 'Insufficient available balance')) {
                $this->auditEvents->record(
                    eventType: 'reservation.insufficient_balance',
                    centralWalletId: $centralWalletId,
                    actorType: AuditActorType::Service,
                    actorId: $callerId,
                    correlationId: $correlationId,
                    payload: [
                        'requested_amount' => $amount,
                        'spendable_balance' => $this->ledger->spendableBalance($centralWalletId),
                        'business_reference' => $businessReference,
                    ],
                );
            }

            throw $exception;
        }
    }

    /**
     * @return array{reservation: CentralWalletReservation, ledger_entry: CentralWalletLedgerEntry}
     */
    public function commit(
        string $reservationId,
        string $callerId,
        string $correlationId,
    ): array {
        return DB::transaction(function () use ($reservationId, $callerId, $correlationId): array {
            $reservation = CentralWalletReservation::query()
                ->lockForUpdate()
                ->find($reservationId);

            if ($reservation === null) {
                throw new InvalidArgumentException('Reservation not found.');
            }

            $this->assertCallerOwnsReservation($reservation, $callerId);

            if ($reservation->state === ReservationState::Committed) {
                $entry = $reservation->ledger_entry_id !== null
                    ? CentralWalletLedgerEntry::query()->find($reservation->ledger_entry_id)
                    : null;

                if ($entry === null) {
                    throw new InvalidArgumentException('Committed reservation is missing ledger entry.');
                }

                return ['reservation' => $reservation, 'ledger_entry' => $entry];
            }

            if ($reservation->state !== ReservationState::Active) {
                throw new InvalidArgumentException('Reservation cannot be committed from state '.$reservation->state->value.'.');
            }

            if ($reservation->isExpiredByTime()) {
                throw new InvalidArgumentException('Reservation has expired.');
            }

            CentralWallet::query()->lockForUpdate()->findOrFail($reservation->central_wallet_id);

            $entry = $this->ledger->appendEntry(
                centralWalletId: $reservation->central_wallet_id,
                entryType: LedgerEntryType::Debit,
                amount: (string) $reservation->amount,
                sourceSystem: $reservation->caller_id,
                correlationId: $correlationId,
                sourceReference: $reservation->source_reference,
                businessReference: $reservation->business_reference,
                reservationId: $reservation->id,
            );

            $this->stateMachine->assertCanTransition($reservation->state, ReservationState::Committed);

            $reservation->update([
                'state' => ReservationState::Committed,
                'committed_at' => now(),
                'ledger_entry_id' => $entry->id,
            ]);

            $this->auditEvents->record(
                eventType: 'reservation.committed',
                centralWalletId: $reservation->central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: $callerId,
                correlationId: $correlationId,
                payload: [
                    'reservation_id' => $reservation->id,
                    'ledger_entry_id' => $entry->id,
                    'amount' => (string) $reservation->amount,
                    'business_reference' => $reservation->business_reference,
                ],
            );

            return ['reservation' => $reservation->fresh(), 'ledger_entry' => $entry];
        });
    }

    public function release(
        string $reservationId,
        string $callerId,
        string $correlationId,
    ): CentralWalletReservation {
        return DB::transaction(function () use ($reservationId, $callerId, $correlationId): CentralWalletReservation {
            $reservation = CentralWalletReservation::query()
                ->lockForUpdate()
                ->find($reservationId);

            if ($reservation === null) {
                throw new InvalidArgumentException('Reservation not found.');
            }

            $this->assertCallerOwnsReservation($reservation, $callerId);

            if (in_array($reservation->state, [ReservationState::Released, ReservationState::Expired], true)) {
                return $reservation;
            }

            if ($reservation->state === ReservationState::Committed) {
                throw new InvalidArgumentException('Committed reservation cannot be released.');
            }

            if ($reservation->state !== ReservationState::Active) {
                throw new InvalidArgumentException('Reservation cannot be released from state '.$reservation->state->value.'.');
            }

            $this->stateMachine->assertCanTransition($reservation->state, ReservationState::Released);

            $reservation->update([
                'state' => ReservationState::Released,
                'released_at' => now(),
            ]);

            $this->auditEvents->record(
                eventType: 'reservation.released',
                centralWalletId: $reservation->central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: $callerId,
                correlationId: $correlationId,
                payload: [
                    'reservation_id' => $reservation->id,
                    'amount' => (string) $reservation->amount,
                    'business_reference' => $reservation->business_reference,
                ],
            );

            return $reservation->fresh();
        });
    }

    public function expireDueReservations(int $batchSize = 100): int
    {
        $ids = CentralWalletReservation::query()
            ->where('state', ReservationState::Active)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($batchSize)
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $reservationId) {
            $didExpire = DB::transaction(function () use ($reservationId): bool {
                $reservation = CentralWalletReservation::query()
                    ->lockForUpdate()
                    ->find($reservationId);

                if ($reservation === null) {
                    return false;
                }

                if ($reservation->state !== ReservationState::Active || ! $reservation->isExpiredByTime()) {
                    return false;
                }

                $this->stateMachine->assertCanTransition($reservation->state, ReservationState::Expired);

                $reservation->update([
                    'state' => ReservationState::Expired,
                    'expired_at' => now(),
                ]);

                $this->auditEvents->record(
                    eventType: 'reservation.expired',
                    centralWalletId: $reservation->central_wallet_id,
                    actorType: AuditActorType::Service,
                    actorId: 'central_wallet:expiry_job',
                    correlationId: (string) Str::uuid(),
                    payload: [
                        'reservation_id' => $reservation->id,
                        'amount' => (string) $reservation->amount,
                        'business_reference' => $reservation->business_reference,
                        'expires_at' => $reservation->expires_at?->toIso8601String(),
                    ],
                );

                return true;
            });

            if ($didExpire) {
                $expired++;
            }
        }

        return $expired;
    }

    private function assertCallerOwnsReservation(CentralWalletReservation $reservation, string $callerId): void
    {
        if ($reservation->caller_id !== $callerId) {
            throw new InvalidArgumentException('Reservation does not belong to this caller.');
        }
    }
}
