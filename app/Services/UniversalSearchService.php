<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UniversalSearchService
{
    public const RESULT_LIMIT = 20;

    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    /**
     * @return Collection<int, Incident>
     */
    public function search(User $user, string $query): Collection
    {
        $query = trim($query);

        if ($query === '' || ! $user->can('incidents.view')) {
            return collect();
        }

        $like = '%'.$query.'%';
        $prefix = $query.'%';

        $builder = Incident::query()
            ->with(['order.deviceModel', 'order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->where(function (Builder $incidentQuery) use ($query, $like): void {
                $incidentQuery->where(function (Builder $referenceQuery) use ($query): void {
                    $referenceQuery->matchingReference($query);
                })->orWhereHas('order', fn (Builder $orderQuery) => $this->applyOrderSearchFilters($orderQuery, $like));
            });

        $paddedReference = $this->paddedReferenceForQuery($query);

        return $builder
            ->orderByRaw(
                'CASE
                    WHEN incidents.reference_no = ?
                      OR incidents.reference_no = ?
                      OR incidents.reference_no = ?
                      OR incidents.reference_no = ?
                      OR EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND (
                            orders.customer_phone = ?
                            OR orders.order_id = ?
                            OR orders.serial_number = ?
                            OR orders.transaction_id = ?
                            OR orders.customer_email = ?
                            OR orders.customer_name = ?
                          )
                      )
                    THEN 0
                    WHEN incidents.reference_no LIKE ?
                      OR EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND (
                            orders.customer_phone LIKE ?
                            OR orders.order_id LIKE ?
                            OR orders.serial_number LIKE ?
                            OR orders.transaction_id LIKE ?
                            OR orders.customer_email LIKE ?
                            OR orders.customer_name LIKE ?
                          )
                      )
                    THEN 1
                    ELSE 2
                END',
                [
                    $paddedReference['sc_dash'],
                    $paddedReference['sc_plain'],
                    $paddedReference['sc_dash_unpadded'],
                    $paddedReference['sc_plain_unpadded'],
                    $query,
                    $query,
                    $query,
                    $query,
                    $query,
                    $query,
                    $prefix,
                    $prefix,
                    $prefix,
                    $prefix,
                    $prefix,
                    $prefix,
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
