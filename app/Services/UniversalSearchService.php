<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\InteraktMessage;
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

        $tokens = $this->searchTokens($query);

        if ($tokens === []) {
            return collect();
        }

        $builder = Incident::query()
            ->with(['order.deviceModel', 'order.transactionAssigner', 'creator', 'assignee']);

        foreach ($tokens as $token) {
            $like = '%'.$token.'%';

            $builder->where(function (Builder $incidentQuery) use ($token, $like): void {
                $incidentQuery->where(function (Builder $referenceQuery) use ($token): void {
                    $referenceQuery->matchingReference($token);
                })->orWhereHas('order', fn (Builder $orderQuery) => $this->applyOrderSearchFilters($orderQuery, $like))
                    ->orWhereHas('closeExceptions', fn (Builder $exceptionQuery) => $exceptionQuery
                        ->where('exception_id', 'like', $like));

                if ($this->settingService->getBool('search.notes_enabled', true)) {
                    $incidentQuery->orWhereHas('remarks', fn (Builder $remarkQuery) => $remarkQuery
                        ->where('body', 'like', $like));
                }

                if ($this->settingService->getBool('search.whatsapp_enabled', true)) {
                    $incidentQuery->orWhereHas('order', fn (Builder $orderQuery) => $orderQuery
                        ->whereIn('customer_phone', InteraktMessage::query()
                            ->select('customer_phone')
                            ->where(function (Builder $messageQuery) use ($like): void {
                                $messageQuery->where('template_name', 'like', $like)
                                    ->orWhere('message_id', 'like', $like)
                                    ->orWhere('conversation_id', 'like', $like)
                                    ->orWhere('interakt_customer_id', 'like', $like);
                            })));
                }
            });
        }

        $referenceExactMatches = $this->referenceExactMatchBindings($query);
        $prefix = $query.'%';

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
                    $referenceExactMatches[0],
                    $referenceExactMatches[1],
                    $referenceExactMatches[2],
                    $referenceExactMatches[3],
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
     * @return list<string>
     */
    public function searchTokens(string $query): array
    {
        $tokens = preg_split('/\s+/u', trim($query));

        if ($tokens === false) {
            return [];
        }

        return array_values(array_filter($tokens, fn (string $token): bool => $token !== ''));
    }

    /**
     * @return list<string>
     */
    private function referenceExactMatchBindings(string $query): array
    {
        $sequence = Incident::parseReferenceSequence($query);

        if ($sequence === null) {
            return [$query, $query, $query, $query];
        }

        return array_slice(Incident::referenceMatchVariants($sequence), 0, 4);
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

            $applied ? $builder->orWhere('device_model', 'like', $like) : $builder->where('device_model', 'like', $like);
            $applied = true;

            $builder->orWhere('product_name', 'like', $like);
            $builder->orWhereHas('deviceModel', fn (Builder $deviceModelQuery) => $deviceModelQuery
                ->where('name', 'like', $like));

            if (! $applied) {
                $builder->whereRaw('0 = 1');
            }
        });
    }
}
