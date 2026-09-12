<?php

namespace App\Services\Purchasing;

use App\Models\PurchasingAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

class PurchasingAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        ?User $actor,
        string $event,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
    ): PurchasingAuditLog {
        $request ??= RequestFacade::instance();

        return PurchasingAuditLog::query()->create([
            'user_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
