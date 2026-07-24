<?php

namespace App\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

readonly class RecentActivityItem
{
    private const REDUNDANT_CHIPS = [
        'Team',
        'Customer',
        'Communication',
        'Activity',
        'Notification',
        'Status',
        'Order',
        'Sync',
    ];

    /** @var array<string, string> Exact title → compact action (≤12 chars). */
    private const ACTION_LABELS = [
        'Communication Sent' => 'Comm Sent',
        'Communication Failed' => 'Comm Fail',
        'Enrichment Completed' => 'Enrich OK',
        'Enrichment Complete' => 'Enrich OK',
        'Enrichment Failed' => 'Enrich Fail',
        'Driver Guide Sent' => 'DG Sent',
        'WhatsApp Message Sent' => 'WA Sent',
        'WhatsApp Message Failed' => 'WA Fail',
        'WhatsApp Sent' => 'WA Sent',
        'Email Sent' => 'Mail Sent',
        'Email Received' => 'Mail In',
        'Email Linked' => 'Mail Link',
        'Email Promoted to Service Case' => 'Mail→SC',
        'IVR Received' => 'IVR In',
        'Missed Call' => 'IVR In',
        'Appointment Reminder' => 'Appt Rem.',
        'Customer Replied' => 'Cust Reply',
        'Refund Completed' => 'Refunded',
        'Refund Approved' => 'Refund OK',
        'Refund Rejected' => 'Refund No',
        'Case Closed' => 'Closed',
        'Closed — Customer Not Responding' => 'Closed',
        'Assigned' => 'Assigned',
        'Reassigned' => 'Reassigned',
        'Status Updated' => 'Status Upd',
        'Service Case Escalated' => 'Escalated',
        'Notification Sent' => 'Notif Sent',
        'Notification Skipped' => 'Notif Skip',
        'Payment Received' => 'Pay Recvd',
        'Waiting for RadiumBox' => 'Wait RB',
        'RadiumBox Verified' => 'RB OK',
        'RadiumBox Synced' => 'RB Sync',
        'Validation Passed' => 'Valid OK',
        'Validation Failed' => 'Valid Fail',
        'Waiting for Customer Input' => 'Wait Cust',
        'Waiting Customer' => 'Wait Cust',
        'Remark Added' => 'Remark',
        'Remark Deleted' => 'Remark Del',
        'Serial Corrected by IRA' => 'Serial Fix',
        'Serial Assigned' => 'Serial',
        'Order Updated' => 'Order Upd',
        'Order Identity Corrected' => 'ID Fixed',
        'Legacy Order Imported' => 'Legacy Imp',
    ];

    public function __construct(
        public string $stream,
        public string $title,
        public ?string $typePill,
        public string $indicatorVariant,
        public ?string $incidentReference,
        public ?string $orderReference,
        public ?string $customerName,
        public ?int $entityIncidentId,
        public ?string $entityReference,
        public Carbon $occurredAt,
        public string $compactTime,
        public string $exactTime,
        public string $actorName,
        public bool $isAutomation,
    ) {}

    public function incidentLabel(): string
    {
        if (filled($this->incidentReference) && filled($this->orderReference)) {
            return $this->incidentReference.' · '.$this->orderReference;
        }

        if (filled($this->incidentReference)) {
            return (string) $this->incidentReference;
        }

        if (filled($this->orderReference)) {
            return (string) $this->orderReference;
        }

        return '';
    }

    public function primaryName(): string
    {
        if ($this->stream === 'customer' && filled($this->customerName)) {
            return (string) $this->customerName;
        }

        if ($this->stream === 'ira') {
            return filled($this->customerName) ? (string) $this->customerName : 'IRA';
        }

        if (filled($this->actorName) && $this->actorName !== 'IRA') {
            return $this->actorName;
        }

        if (filled($this->customerName)) {
            return (string) $this->customerName;
        }

        return $this->stream === 'ira' ? 'IRA' : 'Team';
    }

    public function customer360Label(): string
    {
        if (filled($this->customerName)) {
            return (string) $this->customerName;
        }

        $incidentLabel = $this->incidentLabel();

        return $incidentLabel !== '' ? $incidentLabel : $this->primaryName();
    }

    /**
     * Compact action for the fixed action column (≤12 characters).
     */
    public function actionLabel(): string
    {
        if (isset(self::ACTION_LABELS[$this->title])) {
            return self::ACTION_LABELS[$this->title];
        }

        $title = strtolower($this->title);

        $label = match (true) {
            str_contains($title, 'communication') && str_contains($title, 'fail') => 'Comm Fail',
            str_contains($title, 'communication') => 'Comm Sent',
            str_contains($title, 'enrich') && str_contains($title, 'fail') => 'Enrich Fail',
            str_contains($title, 'enrich') => 'Enrich OK',
            str_contains($title, 'driver guide') => 'DG Sent',
            str_contains($title, 'whatsapp') && str_contains($title, 'fail') => 'WA Fail',
            str_contains($title, 'whatsapp') => 'WA Sent',
            str_contains($title, 'email') && str_contains($title, 'receiv') => 'Mail In',
            str_contains($title, 'email') => 'Mail Sent',
            str_contains($title, 'missed') || str_contains($title, 'ivr') => 'IVR In',
            str_contains($title, 'appointment') => 'Appt Rem.',
            str_contains($title, 'replied') || str_contains($title, 'reply') => 'Cust Reply',
            str_contains($title, 'refund') && str_contains($title, 'complete') => 'Refunded',
            str_contains($title, 'closed') => 'Closed',
            default => Str::limit($this->title, 12, ''),
        };

        return mb_strlen($label) > 12 ? mb_substr($label, 0, 12) : $label;
    }

    /**
     * Semantic icon key for the shared SVG sprite.
     */
    public function iconKey(): string
    {
        $pill = strtolower((string) $this->typePill);
        $title = strtolower($this->title);

        return match (true) {
            $this->stream === 'ira' || $pill === 'ira' || str_contains($title, 'ira')
                || $this->indicatorVariant === 'automation'
                || str_contains($title, 'enrich') || str_contains($title, 'radiumbox') => 'gear',
            $pill === 'whatsapp' || str_contains($title, 'whatsapp') => 'message',
            $pill === 'email' || str_contains($title, 'email') => 'mail',
            $pill === 'payment' || str_contains($title, 'payment')
                || $pill === 'refund' || str_contains($title, 'refund') => 'payment',
            $pill === 'assignment' || str_contains($title, 'assign') => 'user',
            $pill === 'remark' || str_contains($title, 'remark') => 'note',
            $pill === 'ivr' || str_contains($title, 'call') || str_contains($title, 'missed') => 'phone',
            str_contains($title, 'closed') || str_contains($title, 'reject')
                || $this->indicatorVariant === 'error' || $this->indicatorVariant === 'danger' => 'x',
            str_contains($title, 'reopen') => 'refresh',
            $this->indicatorVariant === 'communication' || str_contains($title, 'communication') => 'message',
            $this->indicatorVariant === 'warning' => 'clock',
            default => 'dot',
        };
    }

    /**
     * Legacy emoji icon — prefer iconKey() for UI.
     */
    public function icon(): string
    {
        return match ($this->iconKey()) {
            'gear' => '⚙️',
            'message' => '💬',
            'mail' => '📨',
            'payment' => '💰',
            'user' => '👤',
            'note' => '📝',
            'phone' => '📞',
            'x' => '❌',
            'refresh' => '🔄',
            'clock' => '⏳',
            default => '•',
        };
    }

    /**
     * Tiny channel/system badge (WA, DG, RB, MAIL, IVR). Empty string when unused.
     */
    public function channelBadge(): string
    {
        $pill = strtolower((string) $this->typePill);
        $title = strtolower($this->title);

        return match (true) {
            $pill === 'whatsapp' || str_contains($title, 'whatsapp') => 'WA',
            $pill === 'email' || str_contains($title, 'email') => 'MAIL',
            $pill === 'ivr' || str_contains($title, 'missed call') => 'IVR',
            $pill === 'driver guide' || str_contains($title, 'driver guide') => 'DG',
            $pill === 'sync' || str_contains($title, 'radiumbox') || str_contains($title, 'enrichment') => 'RB',
            default => '',
        };
    }

    /**
     * Outcome mark for fail/warn only. Success stays quiet (null).
     *
     * @return 'fail'|'warn'|null
     */
    public function statusMark(): ?string
    {
        return match ($this->indicatorVariant) {
            'error', 'danger' => 'fail',
            'warning' => 'warn',
            default => null,
        };
    }

    /**
     * Chips that add information beyond section + title + icon.
     *
     * @return list<string>
     */
    public function chips(): array
    {
        if (! filled($this->typePill)) {
            return [];
        }

        $pill = (string) $this->typePill;

        if (in_array($pill, self::REDUNDANT_CHIPS, true)) {
            return [];
        }

        if ($this->stream === 'ira' && $pill === 'IRA') {
            return [];
        }

        return [$pill];
    }
}
