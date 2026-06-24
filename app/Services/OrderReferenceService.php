<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $year = now()->format('Y');
            $prefix = "RD-{$year}-";

            $latestReference = Order::withTrashed()
                ->where('order_id', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('order_id')
                ->value('order_id');

            $sequence = $latestReference
                ? ((int) substr($latestReference, -6)) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
