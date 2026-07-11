<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OrderIdentityProtectionService
{
    public const CUSTOMER_IDENTITY_FIELDS = [
        'customer_name',
        'customer_phone',
        'customer_email',
    ];

    /**
     * @var array<string, array{at: string, by: string}>
     */
    private const FIELD_LOCK_COLUMNS = [
        'customer_name' => [
            'at' => 'customer_name_locked_at',
            'by' => 'customer_name_locked_by',
        ],
        'customer_phone' => [
            'at' => 'customer_phone_locked_at',
            'by' => 'customer_phone_locked_by',
        ],
        'customer_email' => [
            'at' => 'customer_email_locked_at',
            'by' => 'customer_email_locked_by',
        ],
    ];

    /**
     * @param  array<string, ?string>  $candidateValues
     * @return array<string, string>
     */
    public function buildExternalIdentityUpdates(Order $order, array $candidateValues): array
    {
        $updates = [];

        foreach (self::CUSTOMER_IDENTITY_FIELDS as $field) {
            if (! array_key_exists($field, $candidateValues)) {
                continue;
            }

            if ($this->isFieldLocked($order, $field)) {
                continue;
            }

            $value = $candidateValues[$field];

            if (! filled($value)) {
                continue;
            }

            $normalized = $this->normalizeFieldValue($field, (string) $value);

            if ($normalized !== (string) ($order->{$field} ?? '')) {
                $updates[$field] = $normalized;
            }
        }

        return $updates;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function lockAttributesForFields(Order $order, array $fields, User $actor): array
    {
        $now = now();
        $attributes = [];

        foreach ($fields as $field) {
            if (! isset(self::FIELD_LOCK_COLUMNS[$field])) {
                continue;
            }

            if ($this->isFieldLocked($order, $field)) {
                continue;
            }

            $columns = self::FIELD_LOCK_COLUMNS[$field];
            $attributes[$columns['at']] = $now;
            $attributes[$columns['by']] = $actor->id;
        }

        return $attributes;
    }

    /**
     * @param  list<string>|null  $fields
     */
    public function unlockProtectedIdentityFields(Order $order, User $actor, ?array $fields = null): Order
    {
        if (! $actor->can('unlockProtectedIdentityFields', $order)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $fieldsToUnlock = $fields ?? self::CUSTOMER_IDENTITY_FIELDS;
        $attributes = [];

        foreach ($fieldsToUnlock as $field) {
            if (! isset(self::FIELD_LOCK_COLUMNS[$field])) {
                continue;
            }

            $columns = self::FIELD_LOCK_COLUMNS[$field];
            $attributes[$columns['at']] = null;
            $attributes[$columns['by']] = null;
        }

        if ($attributes === []) {
            return $order;
        }

        return DB::transaction(function () use ($order, $attributes, $actor): Order {
            $order->update([
                ...$attributes,
                'updated_by' => $actor->id,
            ]);

            return $order->fresh();
        });
    }

    public function isFieldLocked(Order $order, string $field): bool
    {
        return match ($field) {
            'customer_name' => $order->isCustomerNameLocked(),
            'customer_phone' => $order->isCustomerPhoneLocked(),
            'customer_email' => $order->isCustomerEmailLocked(),
            'serial_number' => $order->isSerialLocked(),
            default => false,
        };
    }

    public function protectionTitleForField(string $field): ?string
    {
        return match ($field) {
            'customer_name' => 'Customer Name protected from external sync',
            'customer_phone' => 'Mobile Number protected from external sync',
            'customer_email' => 'Email Address protected from external sync',
            default => null,
        };
    }

    private function normalizeFieldValue(string $field, string $value): string
    {
        $trimmed = trim($value);

        if ($field === 'customer_email') {
            return strtolower($trimmed);
        }

        return $trimmed;
    }
}
