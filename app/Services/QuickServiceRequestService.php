<?php

namespace App\Services;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickServiceRequestService
{
    public function __construct(
        private readonly IncidentReferenceService $incidentReferenceService,
    ) {}

    public function create(
        User $user,
        string $orderId,
        string $serialNumber,
        string $product,
        IncidentSource $source,
        string $notes,
    ): Incident {
        return DB::transaction(function () use ($user, $orderId, $serialNumber, $product, $source, $notes): Incident {
            $order = Order::query()
                ->where('order_id', $orderId)
                ->first();

            if ($order === null) {
                $serialOwner = Order::query()
                    ->where('serial_number', $serialNumber)
                    ->first();

                if ($serialOwner !== null) {
                    throw ValidationException::withMessages([
                        'serial_number' => 'This serial number belongs to a different order.',
                    ]);
                }

                $order = Order::query()->create([
                    'order_id' => $orderId,
                    'serial_number' => $serialNumber,
                    'product_name' => $product,
                    'device_model' => $product,
                    'status' => OrderStatus::Active,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            } else {
                if ($order->serial_number !== $serialNumber) {
                    throw ValidationException::withMessages([
                        'serial_number' => 'The serial number does not match this order.',
                    ]);
                }
            }

            return Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => $this->incidentReferenceService->generate(),
                'category' => 'General',
                'source' => $source,
                'title' => 'Service request — '.$product,
                'description' => $notes,
                'status' => IncidentStatus::Open,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }
}
