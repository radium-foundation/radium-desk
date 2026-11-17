<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentClassifier;
use App\Support\BusinessOrderId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RbpHardwareFulfilmentGapRecoveryService
{
    public function __construct(
        private readonly RbpHardwareFulfilmentDeskPaymentAuthorityGate $paymentGate,
        private readonly HardwareFulfilmentFoundationService $foundation,
        private readonly HardwareSkuMapService $skuMaps,
    ) {}

    /**
     * @return array{
     *   source_id: string,
     *   status: string,
     *   code: string,
     *   message: string,
     *   hardware_fulfilment_id: ?int,
     *   cashfree_payment_id: ?string,
     * }
     */
    public function recoverOne(string $sourceId, bool $execute): array
    {
        $sourceId = strtoupper(trim($sourceId));
        $execution = RbpHardwareFulfilmentGapRecoveryExecution::forAllowlistedSource($sourceId);

        if (! $execution->isValid()) {
            return $this->result($sourceId, 'skipped', 'not_allowlisted', 'Source id is not on the P-17-43 recovery allowlist.', null, null);
        }

        try {
            $this->assertPreWriteEligibility($sourceId);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Eligibility failed.';

            return $this->result($sourceId, 'skipped', 'ineligible', (string) $message, null, null);
        }

        if (! $execute) {
            return $this->result($sourceId, 'dry_run', 'eligible', 'Eligible for recovery (dry run).', null, null);
        }

        try {
            return DB::transaction(function () use ($sourceId, $execution): array {
                $commerce = $this->uniqueCommerceForSource($sourceId);
                $commerce = $this->paymentGate->lockedCommerceOrder((int) $commerce->id);
                $desk = $this->paymentGate->lockedDeskOrder($sourceId);

                $this->paymentGate->assertDeskPaymentAuthority($desk, $commerce);
                $this->assertPreWriteEligibility($sourceId, $commerce, $desk);

                if (HardwareFulfilment::query()->where('commerce_order_id', $commerce->id)->exists()) {
                    $existing = HardwareFulfilment::query()->where('commerce_order_id', $commerce->id)->first();

                    return $this->result(
                        $sourceId,
                        'skipped',
                        'already_exists',
                        'Hardware fulfilment already exists for this commerce order.',
                        $existing?->id,
                        $desk->cashfree_payment_id,
                    );
                }

                $fulfilment = $this->foundation->ensureIngestedFromExistingCommerceForRbpGapRecovery($commerce, $execution);

                return $this->result(
                    $sourceId,
                    'recovered',
                    'created',
                    'Hardware fulfilment opened from existing commerce.',
                    $fulfilment->id,
                    $desk->cashfree_payment_id,
                );
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $existing = HardwareFulfilment::query()
                ->whereRaw('UPPER(source_id) = ?', [$sourceId])
                ->first();

            return $this->result(
                $sourceId,
                'skipped',
                'idempotent_existing',
                'Concurrent recovery raced; existing fulfilment returned.',
                $existing?->id,
                Order::query()->where('order_id', $sourceId)->value('cashfree_payment_id'),
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Recovery validation failed.';

            return $this->result($sourceId, 'failed', 'validation', (string) $message, null, null);
        }
    }

    /**
     * @return list<string>
     */
    public function configuredAllowlist(): array
    {
        $allowlist = config('hardware_fulfilment.rbp_gap_recovery.allowlist', []);

        if (! is_array($allowlist)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): string => strtoupper(trim((string) $id)),
            $allowlist,
        )));
    }

    private function assertPreWriteEligibility(
        string $sourceId,
        ?CommerceOrder $commerce = null,
        ?Order $desk = null,
    ): void {
        $commerce ??= $this->uniqueCommerceForSource($sourceId);
        $desk ??= Order::query()->where('order_id', $sourceId)->first();

        if ($desk === null) {
            throw ValidationException::withMessages([
                'order' => 'Desk support order is missing.',
            ]);
        }

        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed === null || $parsed['prefix'] !== 'RBP' || $parsed['hardware'] !== true) {
            throw ValidationException::withMessages([
                'source' => 'Source must be a paid RBP hardware product order.',
            ]);
        }

        if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)
            || HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)
            || HardwareFulfilmentEligibility::isFrozenForFulfilment($sourceId, $commerce)) {
            throw ValidationException::withMessages([
                'source' => 'Source is frozen, hold, or blocked.',
            ]);
        }

        if (HardwareFulfilmentEligibility::commerceOrderVoidsFulfilment($commerce)) {
            throw ValidationException::withMessages([
                'commerce' => 'Commerce order voids fulfilment.',
            ]);
        }

        if (! HardwareFulfilmentEligibility::hasHardwareLines($commerce)) {
            throw ValidationException::withMessages([
                'hardware' => 'Physical hardware lines are required.',
            ]);
        }

        if ($this->isMissingProductMapping($commerce)) {
            throw ValidationException::withMessages([
                'mapping' => 'Channel SKU mapping is missing for a physical line.',
            ]);
        }

        if (HardwareFulfilment::query()->whereRaw('UPPER(source_id) = ?', [$sourceId])->exists()) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment already exists.',
            ]);
        }

        $this->paymentGate->assertDeskPaymentAuthority($desk, $commerce);

        $reviewReason = HardwareAwaitingFulfilmentClassifier::reason($desk, $commerce);
        if ($reviewReason->value !== 'review_candidate') {
            throw ValidationException::withMessages([
                'classifier' => 'Order is not a review_candidate gap (classifier='.$reviewReason->value.').',
            ]);
        }
    }

    private function isMissingProductMapping(CommerceOrder $commerce): bool
    {
        $commerce->loadMissing('items');
        foreach ($commerce->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }
            $modelId = $item->model_id !== null ? (int) $item->model_id : null;
            if ($this->skuMaps->findProduct($commerce->channel, $modelId) === null) {
                return true;
            }
        }

        return false;
    }

    private function uniqueCommerceForSource(string $sourceId): CommerceOrder
    {
        $matches = CommerceOrder::query()
            ->with('items')
            ->whereRaw('UPPER(source_id) = ?', [$sourceId])
            ->get();

        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'commerce' => 'Expected exactly one commerce order for this source id.',
            ]);
        }

        return $matches->first();
    }

    /**
     * @return array{
     *   source_id: string,
     *   status: string,
     *   code: string,
     *   message: string,
     *   hardware_fulfilment_id: ?int,
     *   cashfree_payment_id: ?string,
     * }
     */
    private function result(
        string $sourceId,
        string $status,
        string $code,
        string $message,
        ?int $hardwareFulfilmentId,
        ?string $cashfreePaymentId,
    ): array {
        return [
            'source_id' => $sourceId,
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'hardware_fulfilment_id' => $hardwareFulfilmentId,
            'cashfree_payment_id' => $cashfreePaymentId,
        ];
    }
}
