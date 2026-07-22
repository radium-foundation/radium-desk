<?php

namespace App\Models;

use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCaseCloseOutcome extends Model
{
    protected $fillable = [
        'incident_id',
        'reason_for_closing',
        'resolution_type',
        'metadata',
        'closing_summary',
        'notification_preference',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::class,
            'resolution_type' => ServiceCaseCloseResolutionType::class,
            'metadata' => 'array',
            'notification_preference' => ServiceCaseCloseNotificationPreference::class,
            'closed_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return list<string>
     */
    public function metadataLines(): array
    {
        $metadata = $this->metadata ?? [];
        $lines = [];

        if (filled($metadata['expected_from'] ?? null)) {
            $lines[] = 'Expected From: '.ucfirst((string) $metadata['expected_from']);
        }

        if (filled($metadata['expected_date'] ?? null)) {
            $lines[] = 'Expected Date: '.$metadata['expected_date'];
        }

        if (filled($metadata['contact_attempt'] ?? null)) {
            $lines[] = 'Contact Attempt: '.ucfirst((string) $metadata['contact_attempt']);
        }

        if (filled($metadata['attempts'] ?? null)) {
            $lines[] = 'Attempts: '.$metadata['attempts'];
        }

        if (filled($metadata['existing_case_id'] ?? null)) {
            $lines[] = 'Existing Case ID: '.$metadata['existing_case_id'];
        }

        if (filled($metadata['replacement_order_id'] ?? null)) {
            $lines[] = 'Replacement Order ID: '.$metadata['replacement_order_id'];
        }

        if (filled($metadata['approval_reference'] ?? null)) {
            $lines[] = 'Approval Reference: '.$metadata['approval_reference'];
        }

        if (filled($metadata['communication_template_label'] ?? null)) {
            $lines[] = 'Communication Template: '.$metadata['communication_template_label'];
        }

        if (filled($metadata['cnr_communication_preference'] ?? null)) {
            $lines[] = 'Communication Channel: '.ucfirst((string) $metadata['cnr_communication_preference']);
        }

        if (filled($metadata['sticky_agent_user_id'] ?? null)) {
            $stickyAgent = User::query()->find((int) $metadata['sticky_agent_user_id']);

            if ($stickyAgent !== null) {
                $lines[] = 'Sticky Agent: '.$stickyAgent->firstName();
            }
        }

        return $lines;
    }

    public function timelineBody(): string
    {
        $lines = [
            'Reason for Closing:',
            $this->reason_for_closing->label(),
        ];

        if ($this->resolution_type !== null) {
            $lines[] = '';
            $lines[] = 'Resolution Type:';
            $lines[] = $this->resolution_type->label();
        }

        $metadataLines = $this->metadataLines();

        if ($metadataLines !== []) {
            $lines[] = '';
            $lines[] = 'Details:';
            array_push($lines, ...$metadataLines);
        }

        if (filled(trim($this->closing_summary))) {
            $lines[] = '';
            $lines[] = 'Closing Summary:';
            $lines[] = trim($this->closing_summary);
        }

        if ($this->relationLoaded('closer') && $this->closer !== null) {
            $lines[] = '';
            $lines[] = 'Closed By:';
            $lines[] = $this->closer->firstName();
        } elseif ($this->closed_by !== null) {
            $closer = User::query()->find($this->closed_by);

            if ($closer !== null) {
                $lines[] = '';
                $lines[] = 'Closed By:';
                $lines[] = $closer->firstName();
            }
        }

        if ($this->closed_at !== null) {
            $lines[] = '';
            $lines[] = 'Closed At:';
            $lines[] = $this->closed_at->format('d M Y, h:i A');
        }

        return implode("\n", $lines);
    }
}
