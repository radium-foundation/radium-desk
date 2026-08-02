<?php

namespace App\Events\Finance;

use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly RefundRequest $refund,
        public readonly ?User $actor = null,
    ) {}
}
