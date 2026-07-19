<?php

namespace App\Enums\Assignment;

enum AssignmentCapability: string
{
    case SupportAgent = 'support_agent';
    case ReadyQueueAdmin = 'ready_queue_admin';
    case AfterHoursSupport = 'after_hours_support';
    case IncomingEmailSupervisor = 'incoming_email_supervisor';
    case WhatsAppSupervisor = 'whatsapp_supervisor';
    case SalesLeadHandler = 'sales_lead_handler';

    public function label(): string
    {
        return match ($this) {
            self::SupportAgent => 'Support Agent',
            self::ReadyQueueAdmin => 'Ready Queue Admin',
            self::AfterHoursSupport => 'After Hours Support',
            self::IncomingEmailSupervisor => 'Incoming Email Supervisor',
            self::WhatsAppSupervisor => 'WhatsApp Supervisor',
            self::SalesLeadHandler => 'Sales Lead Handler',
        };
    }
}
