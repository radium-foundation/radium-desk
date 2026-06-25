<?php

namespace App\Services;

use App\Models\ApprovalNumber;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SearchService
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    /**
     * @param  array<string, int>  $pages
     * @return array<string, LengthAwarePaginator|null>
     */
    public function search(User $user, ?string $query, array $pages = []): array
    {
        $query = trim((string) $query);

        if ($query === '') {
            return [
                'orders' => null,
                'incidents' => null,
                'approvals' => null,
                'refunds' => null,
            ];
        }

        $like = '%'.$query.'%';
        $prefix = $query.'%';

        return [
            'orders' => $user->can('orders.view')
                ? $this->paginateOrders($query, $like, $prefix, $pages['orders'] ?? 1)
                : null,
            'incidents' => $user->can('incidents.view')
                ? $this->paginateIncidents($query, $like, $prefix, $pages['incidents'] ?? 1)
                : null,
            'approvals' => $user->can('approvals.view')
                ? $this->paginateApprovals($query, $like, $prefix, $pages['approvals'] ?? 1)
                : null,
            'refunds' => $user->can('refunds.view')
                ? $this->paginateRefunds($query, $like, $prefix, $pages['refunds'] ?? 1)
                : null,
        ];
    }

    private function paginateOrders(string $query, string $like, string $prefix, int $page): LengthAwarePaginator
    {
        return Order::query()
            ->where(function (Builder $builder) use ($like) {
                $this->applyOrderSearchFilters($builder, $like);
            })
            ->orderByRaw(
                'CASE
                    WHEN order_id = ? THEN 0
                    WHEN serial_number = ? THEN 1
                    WHEN transaction_id = ? THEN 2
                    WHEN order_id LIKE ? THEN 3
                    WHEN serial_number LIKE ? THEN 4
                    WHEN transaction_id LIKE ? THEN 5
                    WHEN customer_email = ? THEN 6
                    WHEN customer_phone = ? THEN 7
                    WHEN customer_name = ? THEN 8
                    ELSE 9
                END',
                [$query, $query, $query, $prefix, $prefix, $prefix, $query, $query, $query]
            )
            ->orderBy('order_id')
            ->paginate(self::PER_PAGE, ['*'], 'orders_page', $page)
            ->withQueryString();
    }

    private function paginateIncidents(string $query, string $like, string $prefix, int $page): LengthAwarePaginator
    {
        return Incident::query()
            ->with('order')
            ->where(function (Builder $builder) use ($like) {
                $builder->where('reference_no', 'like', $like)
                    ->orWhereHas('order', fn (Builder $orderQuery) => $this->applyOrderSearchFilters($orderQuery, $like));
            })
            ->orderByRaw(
                'CASE
                    WHEN reference_no = ? THEN 0
                    WHEN reference_no LIKE ? THEN 1
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.order_id = ?
                    ) THEN 2
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.serial_number = ?
                    ) THEN 3
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.transaction_id = ?
                    ) THEN 4
                    ELSE 5
                END',
                [$query, $prefix, $query, $query, $query]
            )
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE, ['*'], 'incidents_page', $page)
            ->withQueryString();
    }

    private function paginateApprovals(string $query, string $like, string $prefix, int $page): LengthAwarePaginator
    {
        return ApprovalNumber::query()
            ->where('approval_number', 'like', $like)
            ->orderByRaw(
                'CASE
                    WHEN approval_number = ? THEN 0
                    WHEN approval_number LIKE ? THEN 1
                    ELSE 2
                END',
                [$query, $prefix]
            )
            ->orderBy('approval_number')
            ->paginate(self::PER_PAGE, ['*'], 'approvals_page', $page)
            ->withQueryString();
    }

    private function paginateRefunds(string $query, string $like, string $prefix, int $page): LengthAwarePaginator
    {
        return RefundRequest::query()
            ->with('order')
            ->where(function (Builder $builder) use ($like) {
                $builder->where('reference_no', 'like', $like)
                    ->orWhere('refund_transaction_id', 'like', $like)
                    ->orWhereHas('order', fn (Builder $orderQuery) => $this->applyOrderSearchFilters($orderQuery, $like));
            })
            ->orderByRaw(
                'CASE
                    WHEN reference_no = ? THEN 0
                    WHEN refund_transaction_id = ? THEN 1
                    WHEN reference_no LIKE ? THEN 2
                    WHEN refund_transaction_id LIKE ? THEN 3
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = refund_requests.order_id
                          AND orders.order_id = ?
                    ) THEN 4
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = refund_requests.order_id
                          AND orders.serial_number = ?
                    ) THEN 5
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = refund_requests.order_id
                          AND orders.transaction_id = ?
                    ) THEN 6
                    ELSE 7
                END',
                [$query, $query, $prefix, $prefix, $query, $query, $query]
            )
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE, ['*'], 'refunds_page', $page)
            ->withQueryString();
    }

    private function applyOrderSearchFilters(Builder $orderQuery, string $like): void
    {
        $orderQuery->where(function (Builder $builder) use ($like) {
            $applied = false;

            if ($this->settingService->getBool('search.order_id_enabled', true)) {
                $builder->where('order_id', 'like', $like);
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

            if ($this->settingService->getBool('search.email_enabled', true)) {
                $applied ? $builder->orWhere('customer_email', 'like', $like) : $builder->where('customer_email', 'like', $like);
                $applied = true;
            }

            if ($this->settingService->getBool('search.mobile_enabled', true)) {
                $applied ? $builder->orWhere('customer_phone', 'like', $like) : $builder->where('customer_phone', 'like', $like);
                $applied = true;
            }

            if (! $applied) {
                $builder->whereRaw('0 = 1');
            }
        });
    }

    /**
     * @param  array<string, LengthAwarePaginator|null>  $results
     */
    public function totalResults(array $results): int
    {
        return collect($results)
            ->filter()
            ->sum(fn (LengthAwarePaginator $paginator) => $paginator->total());
    }
}
