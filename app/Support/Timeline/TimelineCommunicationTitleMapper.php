<?php

namespace App\Support\Timeline;

final class TimelineCommunicationTitleMapper
{
    public static function titleFor(string $notificationType): string
    {
        return match ($notificationType) {
            'request_serial_number' => 'Requested Device Serial Number',
            'request_serial' => 'Requested Device Serial Number',
            'request_correct_serial' => 'Requested Correct Serial Number',
            'driver_installation_guide' => 'Driver Installation Guide Sent',
            'customer_waiting_followup' => 'Support Reminder Sent',
            'refund_confirmation' => 'Refund Confirmation Sent',
            'review_request' => 'Review Request Sent',
            'support_appointment_confirmation' => 'Support Appointment Confirmed',
            'buy_rd_service' => 'RD Service Purchase Information Sent',
            'buy_product' => 'Product Purchase Information Sent',
            default => ucfirst(str_replace('_', ' ', $notificationType)).' Sent',
        };
    }

    public static function storyKeyFor(string $notificationType, int $auditLogId): string
    {
        return "notification:{$notificationType}:{$auditLogId}";
    }
}
