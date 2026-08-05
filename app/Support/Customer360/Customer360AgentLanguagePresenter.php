<?php

namespace App\Support\Customer360;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Agent-facing display labels for Customer360 / IRA surfaces (presentation only).
 */
final class Customer360AgentLanguagePresenter
{
    public static function responseOverdueCompact(?Carbon $from): ?string
    {
        $duration = self::compactOverdueDuration($from);

        return $duration !== null ? 'RO '.$duration : null;
    }

    public static function responseOverdueTooltip(?Carbon $from): ?string
    {
        $duration = AppDateFormatter::waitingDuration($from);

        return filled($duration) ? 'Response overdue by '.$duration : null;
    }

    public static function agentPriorityLabel(string $priorityLevel, bool $highPriorityFlag = false): string
    {
        $level = strtolower(trim($priorityLevel));

        if ($highPriorityFlag && $level === 'normal') {
            return 'Medium';
        }

        return match ($level) {
            'critical' => 'High',
            'high' => 'Medium',
            'medium', 'normal' => 'Normal',
            'low' => 'Low',
            default => Str::headline($priorityLevel),
        };
    }

    public static function currentStageLabel(string $statusCode, string $fallbackLabel): string
    {
        return match ($statusCode) {
            'appointment_overdue', 'scheduled' => 'Support Appointment',
            'waiting_customer' => 'Waiting for Customer',
            'blocked_serial' => 'Serial Verification',
            'sla_overdue', 'sla_warning', 'in_progress' => 'In Progress',
            'closed' => $fallbackLabel,
            default => self::stageFromStatusLabel($fallbackLabel),
        };
    }

    /**
     * @return array{label: string, value: string, tone: string}|null
     */
    public static function caseDelayBrief(string $slaStatus, ?Carbon $since): ?array
    {
        $sla = strtolower($slaStatus);

        return match (true) {
            $sla === 'overdue' => [
                'label' => 'Case Delay',
                'value' => Str::title((string) (AppDateFormatter::waitingDuration($since) ?? 'Overdue')),
                'tone' => 'danger',
            ],
            $sla === 'warning' => [
                'label' => 'Case Delay',
                'value' => 'At risk',
                'tone' => 'warning',
            ],
            $sla === 'paused' => [
                'label' => 'Case Delay',
                'value' => 'Paused',
                'tone' => 'warning',
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $appointment
     * @return array{value: string, tone: string}|null
     */
    public static function appointmentConditionBrief(string $statusCode, ?array $appointment): ?array
    {
        if ($statusCode !== 'appointment_overdue' && ! self::isAppointmentOverdue($appointment)) {
            if (! is_array($appointment) || ($appointment['is_completed'] ?? false)) {
                return null;
            }

            if ($appointment['is_active'] ?? false) {
                $date = $appointment['preferred_date'] ?? null;
                $label = $date instanceof Carbon
                    ? $date->format('M j, Y')
                    : (filled($date) ? (string) $date : 'Scheduled');

                return ['value' => $label, 'tone' => 'info'];
            }

            return null;
        }

        $preferredDate = is_array($appointment) ? ($appointment['preferred_date'] ?? null) : null;
        $since = $preferredDate instanceof Carbon
            ? $preferredDate
            : (is_string($preferredDate) && $preferredDate !== '' ? Carbon::parse($preferredDate) : null);
        $duration = AppDateFormatter::waitingDuration($since);
        $value = filled($duration)
            ? 'Overdue ('.Str::title((string) $duration).')'
            : 'Overdue';

        return ['value' => $value, 'tone' => 'danger'];
    }

    /**
     * @param  array<string, mixed>|null  $supportAppointment
     * @param  array<string, mixed>|null  $waitingStateCard
     * @return array{label: string, variant: string}|null
     */
    public static function overdueAttentionChip(
        Incident $incident,
        ?array $supportAppointment = null,
        ?array $waitingStateCard = null,
    ): ?array {
        if (self::isAppointmentOverdue($supportAppointment)) {
            return ['label' => 'Appointment Overdue', 'variant' => 'danger'];
        }

        if (self::isVerificationOverdue($incident)) {
            return ['label' => 'Verification Overdue', 'variant' => 'danger'];
        }

        if (self::isPaymentOverdue($incident, $waitingStateCard)) {
            return ['label' => 'Payment Overdue', 'variant' => 'danger'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $appointment
     */
    public static function isAppointmentOverdue(?array $appointment): bool
    {
        if (! is_array($appointment)) {
            return false;
        }

        if (! (bool) ($appointment['is_active'] ?? false) || (bool) ($appointment['is_completed'] ?? false)) {
            return false;
        }

        $preferredDate = $appointment['preferred_date'] ?? null;
        if ($preferredDate instanceof Carbon) {
            return $preferredDate->copy()->startOfDay()->lt(now()->startOfDay());
        }

        if (is_string($preferredDate) && $preferredDate !== '') {
            return Carbon::parse($preferredDate)->startOfDay()->lt(now()->startOfDay());
        }

        return false;
    }

    private static function isVerificationOverdue(Incident $incident): bool
    {
        if (! $incident->isActive()) {
            return false;
        }

        $incident->loadMissing('order');
        $serial = trim((string) ($incident->order?->serial_number ?? ''));

        if ($serial !== '') {
            return false;
        }

        $waitingReason = strtolower((string) ($incident->activeWaitingState?->waiting_reason?->value ?? ''));

        return str_contains($waitingReason, 'serial')
            || $incident->status === IncidentStatus::AwaitingProductDetails;
    }

    /**
     * @param  array<string, mixed>|null  $waitingStateCard
     */
    private static function isPaymentOverdue(Incident $incident, ?array $waitingStateCard): bool
    {
        if ($incident->status === IncidentStatus::AwaitingProductDetails) {
            return false;
        }

        $waitingReason = strtolower((string) ($waitingStateCard['reason_label'] ?? ''));

        return str_contains($waitingReason, 'payment')
            || str_contains($waitingReason, 'pay');
    }

    private static function stageFromStatusLabel(string $label): string
    {
        $normalized = strtolower(trim($label));

        if (str_contains($normalized, 'appointment')) {
            return 'Support Appointment';
        }

        if (str_contains($normalized, 'serial')) {
            return 'Serial Verification';
        }

        if (str_contains($normalized, 'waiting')) {
            return 'Waiting for Customer';
        }

        return Str::headline($label);
    }

    private static function compactOverdueDuration(?Carbon $from): ?string
    {
        if ($from === null) {
            return null;
        }

        $to = now();
        if ($to->lessThan($from)) {
            return null;
        }

        $diff = $from->diff($to);

        if ($diff->y > 0 || $diff->m > 0) {
            $months = ($diff->y * 12) + $diff->m;

            return max(1, $months).'mo';
        }

        if ($diff->d > 0) {
            return $diff->d.'d';
        }

        if ($diff->h > 0) {
            return $diff->h.'h';
        }

        if ($diff->i > 0) {
            return $diff->i.'m';
        }

        return '1m';
    }
}
