<?php

namespace App\Enums;

enum WhatsAppTemplate: string
{
    case RequestSerialNumber = 'request_serial_number';
    case RequestCorrectSerial = 'request_correct_serial';
    case RepairStarted = 'repair_started';
    case RepairCompleted = 'repair_completed';
    case ReadyForDispatch = 'ready_for_dispatch';
    case RefundUpdate = 'refund_update';
    case RefundConfirmation = 'refund_confirmation';
    case AmcReminder = 'amc_reminder';
    case SupportAppointmentBooked = 'support_appointment_booked';
    case CustomerWaitingFollowup = 'customer_waiting_followup';
    case CallbackSchedule = 'callback_schedule';
    case DriverInstallationGuide = 'driver_installation_guide';
    case ReviewRequest = 'review_request';
    case BuyRdService = 'buy_rd_service';
    case BuyProduct = 'buy_product';

    public function purposeLabel(): string
    {
        return match ($this) {
            self::RequestSerialNumber => 'Request Serial Number',
            self::RequestCorrectSerial => 'Request Correct Serial',
            self::RepairStarted => 'Repair Started',
            self::RepairCompleted => 'Repair Completed',
            self::ReadyForDispatch => 'Ready for Dispatch',
            self::RefundUpdate => 'Refund Update',
            self::RefundConfirmation => 'Refund Confirmation',
            self::AmcReminder => 'AMC Reminder',
            self::SupportAppointmentBooked => 'Support Appointment Booked',
            self::CustomerWaitingFollowup => 'Customer Waiting Follow-up',
            self::CallbackSchedule => 'Callback Schedule',
            self::DriverInstallationGuide => 'Driver Installation Guide',
            self::ReviewRequest => 'Review Request',
            self::BuyRdService => 'Buy RD Service',
            self::BuyProduct => 'Buy Product',
        };
    }
}
