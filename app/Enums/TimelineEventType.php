<?php

namespace App\Enums;

enum TimelineEventType: string
{
    case Payment = 'payment';
    case ServiceCaseCreated = 'service_case_created';
    case Assignment = 'assignment';
    case InternalNote = 'internal_note';
    case AuditEvent = 'audit_event';

    // Reserved for future phases — add handlers in TimelineEventTypeRegistry without UI changes.
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case IvrCall = 'ivr_call';
    case Dispatch = 'dispatch';
    case Replacement = 'replacement';
    case Automation = 'automation';
    case AiSummary = 'ai_summary';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::ServiceCaseCreated => 'Service Case Created',
            self::Assignment => 'Assignment',
            self::InternalNote => 'Internal Note',
            self::AuditEvent => 'Audit Event',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::IvrCall => 'IVR Call',
            self::Dispatch => 'Dispatch',
            self::Replacement => 'Replacement',
            self::Automation => 'Automation',
            self::AiSummary => 'AI Summary',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Payment => 'bi-credit-card',
            self::ServiceCaseCreated => 'bi-tools',
            self::Assignment => 'bi-person-check',
            self::InternalNote => 'bi-chat-left-text',
            self::AuditEvent => 'bi-journal-text',
            self::WhatsApp => 'bi-whatsapp',
            self::Email => 'bi-envelope',
            self::IvrCall => 'bi-telephone',
            self::Dispatch => 'bi-truck',
            self::Replacement => 'bi-arrow-repeat',
            self::Automation => 'bi-robot',
            self::AiSummary => 'bi-stars',
        };
    }

    public function isSupported(): bool
    {
        return match ($this) {
            self::Payment,
            self::ServiceCaseCreated,
            self::Assignment,
            self::InternalNote,
            self::AuditEvent => true,
            default => false,
        };
    }
}
