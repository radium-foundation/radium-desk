<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UniversalSearchService
{
    private const RESULT_LIMIT = 50;

    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    /**
     * @return Collection<int, Incident>
     */
    public function search(User $user, string $query, ?User $assignedTo = null): Collection
    {
        $query = trim($query);

        if ($query === '' || ! $user->can('incidents.view')) {
            return collect();
        }

        $like = '%'.$query.'%';
        $prefix = $query.'%';

        $builder = Incident::query()
            ->with(['order.deviceModel', 'order.transactionAssigner', 'creator', 'assignee'])
            ->where(function (Builder $incidentQuery) use ($query, $like): void {
                $incidentQuery->where(function (Builder $referenceQuery) use ($query): void {
                    $referenceQuery->matchingReference($query);
                })->orWhereHas('order', fn (Builder $orderQuery) => $this->applyOrderSearchFilters($orderQuery, $like));
            });

        if ($assignedTo !== null) {
            $builder->where('assigned_to_user_id', $assignedTo->id);
        }

        $paddedReference = $this->paddedReferenceForQuery($query);

        return $builder
            ->orderByRaw(
                'CASE
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_phone = ?
                    ) THEN 0
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_phone LIKE ?
                    ) THEN 1
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.order_id = ?
                    ) THEN 2
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.order_id LIKE ?
                    ) THEN 3
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.serial_number = ?
                    ) THEN 4
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.serial_number LIKE ?
                    ) THEN 5
                    WHEN incidents.reference_no = ?
                      OR incidents.reference_no = ?
                      OR incidents.reference_no = ?
                      OR incidents.reference_no = ?
                    THEN 6
                    WHEN incidents.reference_no LIKE ?
                    THEN 7
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.transaction_id = ?
                    ) THEN 8
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.transaction_id LIKE ?
                    ) THEN 9
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_name LIKE ?
                    ) THEN 10
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_email = ?
                    ) THEN 11
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_email LIKE ?
                    ) THEN 12
                    ELSE 13
                END',
                [
                    $query,
                    $prefix,
                    $query,
                    $prefix,
                    $query,
                    $prefix,
                    $paddedReference['sc_dash'],
                    $paddedReference['sc_plain'],
                    $paddedReference['sc_dash_unpadded'],
                    $paddedReference['sc_plain_unpadded'],
                    $prefix,
                    $query,
                    $prefix,
                    $like,
                    $query,
                    $prefix,
                ]
            )
            ->orderByDesc('incidents.updated_at')
            ->limit(self::RESULT_LIMIT)
            ->get();
    }

    /**
     * @return array{sc_dash: string, sc_plain: string, sc_dash_unpadded: string, sc_plain_unpadded: string}
     */
    private function paddedReferenceForQuery(string $query): array
    {
        $sequence = Incident::parseReferenceSequence($query);

        if ($sequence === null) {
            return [
                'sc_dash' => $query,
                'sc_plain' => $query,
                'sc_dash_unpadded' => $query,
                'sc_plain_unpadded' => $query,
            ];
        }

        $padded = str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

        return [
            'sc_dash' => 'SC-'.$padded,
            'sc_plain' => 'SC'.$padded,
            'sc_dash_unpadded' => 'SC-'.$sequence,
            'sc_plain_unpadded' => 'SC'.$sequence,
        ];
    }

    public function applyOrderSearchFilters(Builder $orderQuery, string $like): void
    {
        $orderQuery->where(function (Builder $builder) use ($like): void {
            $applied = false;

            if ($this->settingService->getBool('search.mobile_enabled', true)) {
                $builder->where('customer_phone', 'like', $like);
                $applied = true;
            }

            if ($this->settingService->getBool('search.order_id_enabled', true)) {
                $applied ? $builder->orWhere('order_id', 'like', $like) : $builder->where('order_id', 'like', $like);
                $applied = true;
            }

            if ($this->settingService->getBool('search.serial_number_enabled', true)) {
                $applied ? $builder->orWhere('serial_number', 'like', $like) : $builder->where('serial_number', 'like', $like);
                $applied = true;
            }

            if ($this->settingService->getBool('search.transaction_id_enabled', true)) {
                $applied ? $builder->orWhere('transaction_id', 'like', $like) : $builder->where('transaction_id', 'like', $like);
                $applied = true;
            }

            if ($this->settingService->getBool('search.customer_name_enabled', true)) {
                $applied ? $builder->orWhere('customer_name', 'like', $like) : $builder->where('customer_name', 'like', $like);
                $applied = true;
            }

            if ($this->settingService->getBool('search.email_enabled', true)) {
                $applied ? $builder->orWhere('customer_email', 'like', $like) : $builder->where('customer_email', 'like', $like);
                $applied = true;
            }

            if (! $applied) {
                $builder->whereRaw('0 = 1');
            }
        });
    }
}
