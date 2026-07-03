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
    case WhatsAppTemplateSent = 'whatsapp_template_sent';
    case Email = 'email';
    case IvrCall = 'ivr_call';
    case Dispatch = 'dispatch';
    case Replacement = 'replacement';
    case Automation = 'automation';
    case AiSummary = 'ai_summary';
    case Synchronization = 'synchronization';
    case Appointment = 'appointment';
    case Notification = 'notification';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::ServiceCaseCreated => 'Service Case Created',
            self::Assignment => 'Assignment',
            self::InternalNote => 'Internal Note',
            self::AuditEvent => 'Audit Event',
            self::WhatsApp => 'WhatsApp',
            self::WhatsAppTemplateSent => 'WhatsApp Template Sent',
            self::Email => 'Email',
            self::IvrCall => 'IVR Call',
            self::Dispatch => 'Dispatch',
            self::Replacement => 'Replacement',
            self::Automation => 'Automation',
            self::AiSummary => 'IRA AI Analysis',
            self::Synchronization => 'Synchronization',
            self::Appointment => 'Appointment',
            self::Notification => 'Notification',
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
            self::WhatsApp,
            self::WhatsAppTemplateSent => 'bi-whatsapp',
            self::Email,
            self::Notification => 'bi-envelope',
            self::IvrCall => 'bi-telephone',
            self::Dispatch => 'bi-truck',
            self::Replacement => 'bi-arrow-repeat',
            self::Automation => 'bi-robot',
            self::AiSummary => 'bi-stars',
            self::Synchronization => 'bi-arrow-repeat',
            self::Appointment => 'bi-calendar-check',
        };
    }

    public function isSupported(): bool
    {
        return match ($this) {
            self::Payment,
            self::ServiceCaseCreated,
            self::Assignment,
            self::InternalNote,
            self::AuditEvent,
            self::WhatsApp,
            self::WhatsAppTemplateSent,
            self::Email,
            self::Notification,
            self::Synchronization,
            self::Appointment,
            self::Automation => true,
            default => false,
        };
    }

    public function filterCategory(): string
    {
        return match ($this) {
            self::WhatsApp,
            self::WhatsAppTemplateSent => 'notifications',
            self::Email,
            self::Notification => 'notifications',
            self::Payment => 'payments',
            self::ServiceCaseCreated,
            self::Assignment,
            self::InternalNote => 'support',
            self::Synchronization => 'synchronization',
            self::Appointment => 'appointments',
            self::Automation => 'system',
            self::AuditEvent => 'support',
            default => 'system',
        };
    }
}
