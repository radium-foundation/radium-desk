<?php

namespace App\Models;

use App\Enums\PosPaymentIntentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosPaymentIntent extends Model
{
    protected $fillable = [
        'public_ref',
        'tr',
        'sale_idempotency_key',
        'status',
        'branch_id',
        'receiving_bank_account_id',
        'upi_profile_id',
        'vpa_snapshot',
        'payee_name_snapshot',
        'amount',
        'currency',
        'upi_uri',
        'cart_payload',
        'customer_name',
        'customer_phone',
        'reservation_id',
        'created_by',
        'utr',
        'verified_by',
        'verified_at',
        'bank_checked_at',
        'sale_id',
        'expires_at',
        'abandoned_at',
        'abandon_reason',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PosPaymentIntentStatus::class,
            'amount' => 'decimal:2',
            'cart_payload' => 'array',
            'verified_at' => 'datetime',
            'bank_checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function receivingBankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccount::class, 'receiving_bank_account_id');
    }

    public function upiProfile(): BelongsTo
    {
        return $this->belongsTo(FinanceBankAccountUpiProfile::class, 'upi_profile_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'reservation_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function receivingAccountLabel(): string
    {
        $account = $this->receivingBankAccount;

        if ($account === null) {
            return 'Receiving account';
        }

        $lastFour = trim((string) $account->last_four);

        return $account->bank_name.($lastFour !== '' ? ' · '.$lastFour : '');
    }
}
