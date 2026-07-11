<?php

namespace App\Support\Customer360;

use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;

class Customer360HealthCardPresenter
{
    /**
     * @param  array<string, mixed>  $healthCard
     * @param  array<string, int>  $summary
     * @return array<string, mixed>
     */
    public function present(array $healthCard, array $summary, ?string $customerPhone): array
    {
        $openCases = (int) ($summary['open_cases'] ?? 0);
        $closedCases = (int) ($summary['closed_cases'] ?? 0);
        $repeatContact = is_array($healthCard['repeat_contact'] ?? null) ? $healthCard['repeat_contact'] : [];
        $appointmentCounts = $this->appointmentCounts($customerPhone);
        $lastContact = $this->resolveLastContact($healthCard, $repeatContact);
        $preferredChannel = $this->resolvePreferredChannel($healthCard, $repeatContact);

        return [
            'status' => $this->resolveStatus(
                $openCases,
                $appointmentCounts['missed'],
                $repeatContact,
            ),
            'total_orders' => (int) ($summary['total_orders'] ?? 0),
            'active_service_cases' => (int) ($healthCard['active_service_cases'] ?? $openCases),
            'completed_service_cases' => $closedCases,
            'total_appointments' => $appointmentCounts['total'],
            'missed_appointments' => $appointmentCounts['missed'],
            'preferred_channel' => $preferredChannel,
            'last_contact' => $lastContact,
        ];
    }

    /**
     * @param  array<string, mixed>  $repeatContact
     * @return array{status: string, label: string}
     */
    private function resolveStatus(int $openCases, int $missedAppointments, array $repeatContact): array
    {
        $highUrgency = (bool) ($repeatContact['high_urgency'] ?? false);
        $contactsToday = (int) ($repeatContact['total_today'] ?? 0);

        if ($highUrgency || ($openCases > 0 && $missedAppointments > 0)) {
            return ['status' => 'critical', 'label' => 'Critical'];
        }

        if ($openCases > 0 || $missedAppointments > 0 || $contactsToday > 0) {
            return ['status' => 'attention', 'label' => 'Attention'];
        }

        return ['status' => 'healthy', 'label' => 'Healthy'];
    }

    /**
     * @return array{total: int, missed: int}
     */
    private function appointmentCounts(?string $customerPhone): array
    {
        if (! filled($customerPhone)) {
            return ['total' => 0, 'missed' => 0];
        }

        $incidentIds = Incident::query()
            ->whereIn('order_id', Order::query()
                ->where('customer_phone', $customerPhone)
                ->select('id'))
            ->pluck('id');

        if ($incidentIds->isEmpty()) {
            return ['total' => 0, 'missed' => 0];
        }

        $appointments = SupportAppointment::query()
            ->whereIn('incident_id', $incidentIds)
            ->where('status', '!=', SupportAppointmentStatus::Superseded)
            ->with(['incident.supportAppointments'])
            ->get();

        $today = now()->startOfDay();
        $openStatuses = array_map(
            fn (IncidentStatus $status) => $status->value,
            IncidentStatus::operationallyActive(),
        );

        $missed = $appointments
            ->filter(function (SupportAppointment $appointment) use ($today, $openStatuses): bool {
                if ($appointment->status !== SupportAppointmentStatus::Scheduled) {
                    return false;
                }

                if ($appointment->preferred_date === null || ! $appointment->preferred_date->lt($today)) {
                    return false;
                }

                $incident = $appointment->incident;

                if ($incident === null || ! in_array($incident->status->value, $openStatuses, true)) {
                    return false;
                }

                return ! $this->hasSupersedingAppointment($appointment);
            })
            ->count();

        return [
            'total' => $appointments->count(),
            'missed' => $missed,
        ];
    }

    private function hasSupersedingAppointment(SupportAppointment $appointment): bool
    {
        $incident = $appointment->incident;

        if ($incident === null) {
            return false;
        }

        return $incident->supportAppointments->contains(
            fn (SupportAppointment $other): bool => $other->id !== $appointment->id
                && $other->preferred_date !== null
                && $other->preferred_date->greaterThan($appointment->preferred_date),
        );
    }

    /**
     * @param  array<string, mixed>  $healthCard
     * @param  array<string, mixed>  $repeatContact
     */
    private function resolvePreferredChannel(array $healthCard, array $repeatContact): ?string
    {
        $latest = $this->latestContactCandidate($healthCard, $repeatContact);

        return $latest['channel'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $healthCard
     * @param  array<string, mixed>  $repeatContact
     * @return array{label: string, channel: string, occurred_at: Carbon}|null
     */
    private function resolveLastContact(array $healthCard, array $repeatContact): ?array
    {
        $latest = $this->latestContactCandidate($healthCard, $repeatContact);

        if ($latest === null) {
            return null;
        }

        return [
            'label' => $latest['channel'],
            'channel' => $latest['channel'],
            'occurred_at' => $latest['occurred_at'],
            'occurred_at_label' => AppDateFormatter::timelineRelative($latest['occurred_at'])
                ?? AppDateFormatter::timelineDatetime($latest['occurred_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $healthCard
     * @param  array<string, mixed>  $repeatContact
     * @return array{channel: string, occurred_at: Carbon}|null
     */
    private function latestContactCandidate(array $healthCard, array $repeatContact): ?array
    {
        $candidates = [];

        $whatsappAt = $healthCard['last_whatsapp']['last_sent_at'] ?? null;
        if ($whatsappAt instanceof Carbon) {
            $candidates[] = ['channel' => 'WhatsApp', 'occurred_at' => $whatsappAt];
        }

        $emailAt = $healthCard['last_email']['last_sent_at'] ?? null;
        if ($emailAt instanceof Carbon) {
            $candidates[] = ['channel' => 'Email', 'occurred_at' => $emailAt];
        }

        $callAt = $healthCard['last_call']['occurred_at'] ?? null;
        if ($callAt instanceof Carbon) {
            $candidates[] = ['channel' => 'Phone', 'occurred_at' => $callAt];
        }

        $repeatAt = $repeatContact['last_contact_at'] ?? null;
        if ($repeatAt instanceof Carbon) {
            $candidates[] = ['channel' => 'Phone', 'occurred_at' => $repeatAt];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            fn (array $left, array $right): int => $right['occurred_at']->timestamp <=> $left['occurred_at']->timestamp,
        );

        return $candidates[0];
    }
}
