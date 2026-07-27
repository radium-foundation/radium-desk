<?php

namespace App\Enums;

enum BusinessMilestoneType: string
{
    case CustomerContact = 'customer_contact';
    case CustomerReply = 'customer_reply';
    case Appointment = 'appointment';
    case EngineerAssignment = 'engineer_assignment';
    case OwnershipChange = 'ownership_change';
    case WaitingStarted = 'waiting_started';
    case WaitingCleared = 'waiting_cleared';
    case PaymentReceived = 'payment_received';
    case SerialVerified = 'serial_verified';
    case SerialPending = 'serial_pending';
    case RepairStarted = 'repair_started';
    case RepairCompleted = 'repair_completed';
    case Escalation = 'escalation';
    case SlaBreached = 'sla_breached';
    case Closure = 'closure';
    case OutboundWhatsApp = 'outbound_whatsapp';
    case OutboundEmail = 'outbound_email';
    case OutboundCall = 'outbound_call';
    case InternalNote = 'internal_note';
    case CaseCreated = 'case_created';
    case SystemUpdate = 'system_update';

    public function label(): string
    {
        return match ($this) {
            self::CustomerContact => 'Customer Contact',
            self::CustomerReply => 'Customer Reply',
            self::Appointment => 'Appointment',
            self::EngineerAssignment => 'Engineer Assignment',
            self::OwnershipChange => 'Ownership Change',
            self::WaitingStarted => 'Waiting Started',
            self::WaitingCleared => 'Waiting Cleared',
            self::PaymentReceived => 'Payment Received',
            self::SerialVerified => 'Serial Verified',
            self::SerialPending => 'Serial Pending',
            self::RepairStarted => 'Repair Started',
            self::RepairCompleted => 'Repair Completed',
            self::Escalation => 'Escalation',
            self::SlaBreached => 'SLA Breached',
            self::Closure => 'Closure',
            self::OutboundWhatsApp => 'WhatsApp',
            self::OutboundEmail => 'Email',
            self::OutboundCall => 'Call',
            self::InternalNote => 'Internal Note',
            self::CaseCreated => 'Service Request',
            self::SystemUpdate => 'System Update',
        };
    }

    public function allowsClustering(): bool
    {
        return match ($this) {
            self::OutboundWhatsApp,
            self::OutboundEmail,
            self::OutboundCall,
            self::SystemUpdate,
            self::OwnershipChange,
            self::EngineerAssignment,
            self::InternalNote,
            self::CustomerReply,
            self::CustomerContact => true,
            default => false,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CustomerContact, self::CustomerReply => 'bi-person',
            self::Appointment => 'bi-calendar-check',
            self::EngineerAssignment, self::OwnershipChange => 'bi-person-check',
            self::WaitingStarted, self::WaitingCleared => 'bi-hourglass-split',
            self::PaymentReceived => 'bi-credit-card',
            self::SerialVerified, self::SerialPending => 'bi-upc-scan',
            self::RepairStarted, self::RepairCompleted => 'bi-wrench',
            self::Escalation => 'bi-exclamation-triangle',
            self::SlaBreached => 'bi-clock-history',
            self::Closure => 'bi-check-circle',
            self::OutboundWhatsApp => 'bi-whatsapp',
            self::OutboundEmail => 'bi-envelope',
            self::OutboundCall => 'bi-telephone',
            self::InternalNote => 'bi-chat-left-text',
            self::CaseCreated => 'bi-tools',
            self::SystemUpdate => 'bi-gear',
        };
    }
}
