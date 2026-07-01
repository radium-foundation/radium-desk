<?php

namespace App\Models;

use App\Enums\ServiceCaseCloseExceptionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCaseCloseException extends Model
{
    protected $fillable = [
        'incident_id',
        'exception_id',
        'serial_number_unavailable',
        'reference_number_unavailable',
        'reason',
        'reason_custom',
        'notify_whatsapp',
        'notify_email',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'serial_number_unavailable' => 'boolean',
            'reference_number_unavailable' => 'boolean',
            'notify_whatsapp' => 'boolean',
            'notify_email' => 'boolean',
            'reason' => ServiceCaseCloseExceptionReason::class,
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayReason(): string
    {
        if ($this->reason === ServiceCaseCloseExceptionReason::Other) {
            return trim((string) $this->reason_custom) ?: $this->reason->label();
        }

        return $this->reason->label();
    }
}
