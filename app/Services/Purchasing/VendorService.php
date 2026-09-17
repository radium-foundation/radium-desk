<?php

namespace App\Services\Purchasing;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorService
{
    public function __construct(
        private readonly PurchasingAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Vendor
    {
        return DB::transaction(function () use ($data, $actor): Vendor {
            $vendor = Vendor::query()->create($this->normalizeVendorData($data));
            $this->audit->log($actor, 'vendor.created', $vendor, null, $vendor->toArray());

            return $vendor;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Vendor $vendor, array $data, User $actor): Vendor
    {
        return DB::transaction(function () use ($vendor, $data, $actor): Vendor {
            $old = $vendor->toArray();
            $vendor->update($this->normalizeVendorData($data, $vendor));
            $this->audit->log($actor, 'vendor.updated', $vendor, $old, $vendor->fresh()->toArray());

            return $vendor->fresh();
        });
    }

    public function setActive(Vendor $vendor, bool $isActive, User $actor): Vendor
    {
        return DB::transaction(function () use ($vendor, $isActive, $actor): Vendor {
            $old = ['is_active' => $vendor->is_active];
            $vendor->update(['is_active' => $isActive]);
            $this->audit->log(
                $actor,
                $isActive ? 'vendor.activated' : 'vendor.deactivated',
                $vendor,
                $old,
                ['is_active' => $isActive],
            );

            return $vendor->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeVendorData(array $data, ?Vendor $existing = null): array
    {
        $gstin = isset($data['gstin']) ? strtoupper(trim((string) $data['gstin'])) : $existing?->gstin;
        $pan = isset($data['pan']) ? strtoupper(trim((string) $data['pan'])) : $existing?->pan;

        if ($gstin !== null && $gstin !== '' && Vendor::query()
            ->where('gstin', $gstin)
            ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists()) {
            throw ValidationException::withMessages([
                'gstin' => 'A vendor with this GSTIN already exists.',
            ]);
        }

        return [
            'vendor_code' => $data['vendor_code'] ?? $existing?->vendor_code,
            'business_name' => trim((string) ($data['business_name'] ?? $existing?->business_name ?? '')),
            'legal_name' => filled($data['legal_name'] ?? $existing?->legal_name) ? trim((string) $data['legal_name']) : null,
            'gstin' => $gstin !== '' ? $gstin : null,
            'pan' => $pan !== '' ? $pan : null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : $existing?->phone,
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : $existing?->email,
            'billing_address' => filled($data['billing_address'] ?? null) ? trim((string) $data['billing_address']) : $existing?->billing_address,
            'city' => filled($data['city'] ?? null) ? trim((string) $data['city']) : $existing?->city,
            'state' => filled($data['state'] ?? null) ? trim((string) $data['state']) : $existing?->state,
            'country' => filled($data['country'] ?? null) ? trim((string) $data['country']) : ($existing?->country ?? 'India'),
            'pin' => filled($data['pin'] ?? null) ? trim((string) $data['pin']) : $existing?->pin,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : $existing?->notes,
        ];
    }
}
