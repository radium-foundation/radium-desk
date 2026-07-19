<?php

namespace App\Models;

use App\Casts\TeamAvailabilityStatusCast;
use App\Enums\TeamAvailabilityStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'first_name',
    'last_name',
    'name',
    'email',
    'password',
    'is_active',
    'telegram_chat_id',
    'telegram_notifications_enabled',
    'bonvoice_extension',
    'availability_status',
    'availability_updated_at',
    'leave_start_date',
    'leave_end_date',
    'last_active_at',
    'last_case_action_at',
    'last_customer_communication_at',
    'last_status_change_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'telegram_notifications_enabled' => 'boolean',
            'availability_status' => TeamAvailabilityStatusCast::class,
            'availability_updated_at' => 'datetime',
            'leave_start_date' => 'date',
            'leave_end_date' => 'date',
            'last_active_at' => 'datetime',
            'last_case_action_at' => 'datetime',
            'last_customer_communication_at' => 'datetime',
            'last_status_change_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('first_name') || $user->isDirty('last_name')) {
                $user->name = trim(trim((string) $user->first_name).' '.trim((string) $user->last_name));

                return;
            }

            if ($user->isDirty('name')) {
                $name = trim((string) $user->name);
                $firstName = Str::before($name, ' ') ?: $name;
                $user->first_name = $firstName;
                $user->last_name = trim(Str::after($name, $firstName));
            }
        });
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

    public function iraNotifications(): HasMany
    {
        return $this->hasMany(IraNotification::class);
    }

    public function assignedIncidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'assigned_to_user_id');
    }

    public function workSchedule(): HasOne
    {
        return $this->hasOne(TeamMemberWorkSchedule::class);
    }

    public function assignmentCapabilities(): HasMany
    {
        return $this->hasMany(UserAssignmentCapability::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function firstName(): string
    {
        $firstName = trim((string) $this->first_name);

        if ($firstName !== '') {
            return $firstName;
        }

        $name = trim((string) $this->name);

        if ($name === '') {
            return '';
        }

        return Str::before($name, ' ') ?: $name;
    }

    public function lastName(): string
    {
        $lastName = trim((string) $this->last_name);

        if ($lastName !== '') {
            return $lastName;
        }

        $name = trim((string) $this->name);

        if ($name === '') {
            return '';
        }

        $firstName = $this->firstName();

        if ($firstName === $name) {
            return '';
        }

        return trim(Str::after($name, $firstName));
    }

    public function initials(): string
    {
        $first = Str::substr($this->firstName(), 0, 1);
        $last = Str::substr($this->lastName(), 0, 1);

        return strtoupper($first.$last) ?: '?';
    }

    public function primaryRoleLabel(): string
    {
        $roleName = $this->roles->first()?->name;

        return app(\App\Services\Operations\OperationsRoleService::class)->displayLabel($roleName);
    }

    public function roleActorLabel(): string
    {
        $roleLabel = $this->primaryRoleLabel();
        $firstName = $this->firstName();

        if ($roleLabel === '') {
            return $firstName;
        }

        if ($firstName === '') {
            return $roleLabel;
        }

        return "{$roleLabel} {$firstName}";
    }
}
