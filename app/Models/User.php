<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    public function updatedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'updated_by');
    }

    public function createdIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'created_by');
    }

    public function updatedIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'updated_by');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(Remark::class);
    }

    public function approvalNumbers(): HasMany
    {
        return $this->hasMany(ApprovalNumber::class, 'created_by');
    }

    public function requestedRefunds(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'requested_by');
    }

    public function reviewedRefunds(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'reviewed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function telegramNotifications(): HasMany
    {
        return $this->hasMany(TelegramNotification::class);
    }

    public function firstName(): string
    {
        $name = trim($this->name);

        if ($name === '') {
            return '';
        }

        return Str::before($name, ' ') ?: $name;
    }
}
