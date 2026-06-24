<?php

namespace App\Services;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QuickServiceRequestService
{
    public function __construct(
        private readonly OrderReferenceService $orderReferenceService,
        private readonly IncidentReferenceService $incidentReferenceService,
    ) {}

    public function create(
        User $user,
        string $customerId,
        string $serialNumber,
        string $product,
        string $notes,
    ): Incident {
        return DB::transaction(function () use ($user, $customerId, $serialNumber, $product, $notes): Incident {
            $order = Order::query()
                ->where('serial_number', $serialNumber)
                ->first();

            if ($order === null) {
                $order = Order::query()->create([
                    'order_id' => $this->orderReferenceService->generate(),
                    'customer_id' => $customerId,
                    'serial_number' => $serialNumber,
                    'product_name' => $product,
                    'device_model' => $product,
                    'status' => OrderStatus::Active,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            } else {
                $order->update([
                    'customer_id' => $customerId,
                    'updated_by' => $user->id,
                ]);
            }

            return Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $this->incidentReferenceService->generate(),
                'category' => 'General',
                'source' => IncidentSource::Internal,
                'title' => 'Service request — '.$product,
                'description' => $notes,
                'status' => IncidentStatus::Open,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }
}
