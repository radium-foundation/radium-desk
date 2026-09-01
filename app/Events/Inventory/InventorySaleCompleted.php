<?php

namespace App\Events\Inventory;

use App\Models\InventorySale;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventorySaleCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InventorySale $sale,
    ) {}
}
