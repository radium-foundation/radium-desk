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
                $builder->where('order_id', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('transaction_id', 'like', $like)
                    ->orWhere('customer_id', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_email', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like);
            })
            ->orderByRaw(
                'CASE
                    WHEN order_id = ? THEN 0
                    WHEN serial_number = ? THEN 1
                    WHEN transaction_id = ? THEN 2
                    WHEN customer_id = ? THEN 3
                    WHEN order_id LIKE ? THEN 4
                    WHEN serial_number LIKE ? THEN 5
                    WHEN transaction_id LIKE ? THEN 6
                    WHEN customer_id LIKE ? THEN 7
                    WHEN customer_email = ? THEN 8
                    WHEN customer_phone = ? THEN 9
                    WHEN customer_name = ? THEN 10
                    ELSE 11
                END',
                [$query, $query, $query, $query, $prefix, $prefix, $prefix, $prefix, $query, $query, $query]
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
                    ->orWhereHas('order', function (Builder $orderQuery) use ($like) {
                        $orderQuery->where('order_id', 'like', $like)
                            ->orWhere('serial_number', 'like', $like)
                            ->orWhere('transaction_id', 'like', $like)
                            ->orWhere('customer_id', 'like', $like)
                            ->orWhere('customer_name', 'like', $like)
                            ->orWhere('customer_email', 'like', $like)
                            ->orWhere('customer_phone', 'like', $like);
                    });
            })
            ->orderByRaw(
                'CASE
                    WHEN reference_no = ? THEN 0
                    WHEN reference_no LIKE ? THEN 1
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_id = ?
                    ) THEN 2
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = incidents.order_id
                          AND orders.customer_id LIKE ?
                    ) THEN 3
                    ELSE 4
                END',
                [$query, $prefix, $query, $prefix]
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
                    ->orWhereHas('order', function (Builder $orderQuery) use ($like) {
                        $orderQuery->where('order_id', 'like', $like)
                            ->orWhere('serial_number', 'like', $like)
                            ->orWhere('transaction_id', 'like', $like)
                            ->orWhere('customer_id', 'like', $like)
                            ->orWhere('customer_name', 'like', $like)
                            ->orWhere('customer_email', 'like', $like)
                            ->orWhere('customer_phone', 'like', $like);
                    });
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
                          AND orders.customer_id = ?
                    ) THEN 4
                    WHEN EXISTS (
                        SELECT 1 FROM orders
                        WHERE orders.id = refund_requests.order_id
                          AND orders.customer_id LIKE ?
                    ) THEN 5
                    ELSE 6
                END',
                [$query, $query, $prefix, $prefix, $query, $prefix]
            )
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE, ['*'], 'refunds_page', $page)
            ->withQueryString();
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
