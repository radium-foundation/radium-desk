<?php

namespace App\Services\CustomerOrderHistory;

use App\Data\CustomerOrderHistory\CustomerOrderHistoryIdentityResolution;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Support\BusinessOrderId;
use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Support\Collection;

class CustomerOrderHistoryService
{
    public function __construct(
        private readonly CustomerOrderHistoryIdentityResolver $identityResolver,
        private readonly CustomerOrderHistoryValidityPresenter $validityPresenter,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
    ) {}

    /**
     * @return array{
     *     resolved: bool,
     *     ambiguous: bool,
     *     ambiguity_reason: ?string,
     *     orders: list<array<string, mixed>>,
     *     warnings: list<array<string, mixed>>,
     * }
     */
    public function historyForContext(
        ?string $phone = null,
        ?string $email = null,
        ?string $businessOrderId = null,
        ?string $serialNumber = null,
        ?int $highlightOrderDbId = null,
    ): array {
        $identity = $this->identityResolver->resolve($phone, $email, $businessOrderId, $serialNumber);

        if ($identity->ambiguous) {
            return [
                'resolved' => false,
                'ambiguous' => true,
                'ambiguity_reason' => $identity->ambiguityReason,
                'orders' => [],
                'warnings' => [[
                    'code' => 'identity_ambiguous',
                    'message' => $identity->ambiguityReason,
                ]],
            ];
        }

        if (! $identity->resolved) {
            return [
                'resolved' => false,
                'ambiguous' => false,
                'ambiguity_reason' => null,
                'orders' => [],
                'warnings' => [],
            ];
        }

        $orders = $this->ordersForIdentity($identity);

        $rows = [];
        $warnings = [];

        foreach ($orders as $order) {
            $row = $this->presentOrder($order, $highlightOrderDbId);
            $rows[] = $row;

            foreach ($row['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }

        return [
            'resolved' => true,
            'ambiguous' => false,
            'ambiguity_reason' => null,
            'orders' => $rows,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    private function ordersForIdentity(CustomerOrderHistoryIdentityResolution $identity): Collection
    {
        $query = Order::query()->orderByDesc('created_at')->orderByDesc('id');

        $query->where(function ($builder) use ($identity): void {
            $applied = false;

            if ($identity->customerIds !== []) {
                $builder->whereIn('customer_id', $identity->customerIds);
                $applied = true;
            }

            if ($identity->phones !== []) {
                $method = $applied ? 'orWhereIn' : 'whereIn';
                $builder->{$method}('customer_phone', $identity->phones);
                $applied = true;
            }

            if ($identity->emails !== []) {
                $method = $applied ? 'orWhere' : 'where';
                $builder->{$method}(function ($emailQuery) use ($identity): void {
                    foreach ($identity->emails as $index => $email) {
                        if ($index === 0) {
                            $emailQuery->whereRaw('LOWER(TRIM(customer_email)) = ?', [$email]);
                        } else {
                            $emailQuery->orWhereRaw('LOWER(TRIM(customer_email)) = ?', [$email]);
                        }
                    }
                });
            }
        });

        return $query->get()->filter(function (Order $order): bool {
            if ($order->isInquiryOrder()) {
                return false;
            }

            $parsed = BusinessOrderId::parse($order->order_id);

            if ($parsed === null) {
                return false;
            }

            return $parsed['kind'] === 'service';
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(Order $order, ?int $highlightOrderDbId): array
    {
        $parsed = BusinessOrderId::parse($order->order_id);
        $metadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $validity = $this->validityPresenter->present($order, $metadata);

        $serial = OrderSerialOwnership::normalizeSerial($order->serial_number);
        $rowWarnings = [];

        if ($serial === null) {
            $rowWarnings[] = [
                'code' => 'serial_missing',
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'message' => 'Serial number is missing on this order.',
            ];
        } else {
            $owner = OrderSerialOwnership::findOwner($serial, $order->id);
            if ($owner !== null) {
                $rowWarnings[] = [
                    'code' => 'serial_conflict',
                    'order_id' => $order->order_id,
                    'order_db_id' => $order->id,
                    'serial_number' => $serial,
                    'conflict_owner_order_id' => $owner->order_id,
                    'message' => "Serial {$serial} is already assigned to order {$owner->order_id}.",
                ];
            }
        }

        $skipped = $metadata['serial_assignment_conflict'] ?? null;
        if (is_array($skipped) && filled($skipped['owner_order_id'] ?? null)) {
            $rowWarnings[] = [
                'code' => 'enrichment_serial_skipped',
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'serial_number' => $skipped['serial'] ?? null,
                'conflict_owner_order_id' => $skipped['owner_order_id'],
                'message' => 'Enrichment could not assign the serial because another order owns it.',
            ];
        }

        return [
            'id' => $order->id,
            'order_id' => $order->order_id,
            'order_date' => $order->created_at?->toIso8601String(),
            'spoke' => $parsed['owner'] ?? 'unknown',
            'spoke_label' => $this->spokeLabel($parsed['owner'] ?? null),
            'product_service' => $order->displayDeviceModelName(),
            'serial_number' => $serial,
            'serial_missing' => $serial === null,
            'validity_status' => $validity['status'],
            'validity_label' => $validity['label'],
            'warranty' => $validity['warranty'],
            'amc' => $validity['amc'],
            'is_highlighted' => $highlightOrderDbId !== null && (int) $highlightOrderDbId === (int) $order->id,
            'warnings' => $rowWarnings,
        ];
    }

    private function spokeLabel(?string $owner): string
    {
        return match ($owner) {
            'rdservice.in' => 'RD Service (rdservice.in)',
            'radiumbox.com' => 'RadiumBox (radiumbox.com)',
            'rdservice.net' => 'RD Service (rdservice.net)',
            'radiumsign.com' => 'Radium Sign',
            default => 'Unknown spoke',
        };
    }
}
