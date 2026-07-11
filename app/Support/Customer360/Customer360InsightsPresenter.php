<?php

namespace App\Support\Customer360;

use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\AuditLog;
use App\Models\CustomerDataCorrection;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SupportAppointment;
use App\Services\SerialValidation\RequestCorrectSerialAuditService;

class Customer360InsightsPresenter
{
    private const MAX_INSIGHTS = 8;

    /**
     * @param  array<string, mixed>  $healthCardViewModel
     * @param  array<string, int>  $summary
     * @return list<array{key: string, label: string, description: string, icon: string}>
     */
    public function present(array $healthCardViewModel, array $summary, ?string $customerPhone): array
    {
        $insights = [];
        $totalOrders = (int) ($summary['total_orders'] ?? 0);

        if ($totalOrders >= 2) {
            $insights[] = $this->insight(
                key: 'repeat_customer',
                label: 'Repeat Customer',
                description: sprintf('%s orders on record', number_format($totalOrders)),
                icon: 'bi-arrow-repeat',
            );
        }

        $preferredChannel = $healthCardViewModel['preferred_channel'] ?? null;

        if (filled($preferredChannel)) {
            $insights[] = $this->insight(
                key: 'preferred_communication_channel',
                label: 'Preferred Communication Channel',
                description: sprintf('Usually reaches out via %s', $preferredChannel),
                icon: 'bi-chat-dots',
            );
        }

        $totalAppointments = (int) ($healthCardViewModel['total_appointments'] ?? 0);
        $missedAppointments = (int) ($healthCardViewModel['missed_appointments'] ?? 0);

        if ($totalAppointments > 0 && $missedAppointments === 0) {
            $insights[] = $this->insight(
                key: 'reliable_appointment_attendance',
                label: 'Reliable Appointment Attendance',
                description: 'No missed appointments on record',
                icon: 'bi-calendar-check',
            );
        }

        if ($totalOrders >= 1 && $this->hasRefundFreeHistory($customerPhone)) {
            $insights[] = $this->insight(
                key: 'refund_free_history',
                label: 'Refund-Free History',
                description: 'No refund requests on record',
                icon: 'bi-shield-check',
            );
        }

        if ($this->hasRemoteFirstResolutionHistory($customerPhone)) {
            $insights[] = $this->insight(
                key: 'remote_first_resolution_history',
                label: 'Remote-First Resolution History',
                description: 'Past cases closed without completed field visits',
                icon: 'bi-laptop',
            );
        }

        if ($this->hasIdentityCorrection($customerPhone)) {
            $insights[] = $this->insight(
                key: 'previous_identity_correction',
                label: 'Previous Identity Correction',
                description: 'Customer details were corrected previously',
                icon: 'bi-person-check',
            );
        }

        if ($this->hasSerialCorrection($customerPhone)) {
            $insights[] = $this->insight(
                key: 'previous_serial_correction',
                label: 'Previous Serial Correction',
                description: 'Serial number was corrected previously',
                icon: 'bi-upc-scan',
            );
        }

        $activeDevices = $this->activeDeviceCount($customerPhone);

        if ($activeDevices >= 2) {
            $insights[] = $this->insight(
                key: 'multiple_active_devices',
                label: 'Multiple Active Devices',
                description: sprintf('%s active devices registered', number_format($activeDevices)),
                icon: 'bi-hdd-stack',
            );
        }

        return array_slice($insights, 0, self::MAX_INSIGHTS);
    }

    /**
     * @return array{key: string, label: string, description: string, icon: string}
     */
    private function insight(string $key, string $label, string $description, string $icon): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'icon' => $icon,
        ];
    }

    private function hasRefundFreeHistory(?string $customerPhone): bool
    {
        $orderIds = $this->orderIds($customerPhone);

        if ($orderIds->isEmpty()) {
            return false;
        }

        return ! RefundRequest::query()
            ->whereIn('order_id', $orderIds)
            ->exists();
    }

    private function hasRemoteFirstResolutionHistory(?string $customerPhone): bool
    {
        $orderIds = $this->orderIds($customerPhone);

        if ($orderIds->isEmpty()) {
            return false;
        }

        $closedStatuses = [
            IncidentStatus::Resolved->value,
            IncidentStatus::Closed->value,
        ];

        $closedCount = Incident::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', $closedStatuses)
            ->count();

        if ($closedCount === 0) {
            return false;
        }

        $incidentIds = Incident::query()
            ->whereIn('order_id', $orderIds)
            ->pluck('id');

        $completedAppointments = SupportAppointment::query()
            ->whereIn('incident_id', $incidentIds)
            ->where('status', SupportAppointmentStatus::Completed)
            ->count();

        return $completedAppointments === 0;
    }

    private function hasIdentityCorrection(?string $customerPhone): bool
    {
        $orderIds = $this->orderIds($customerPhone);

        if ($orderIds->isEmpty()) {
            return false;
        }

        return CustomerDataCorrection::query()
            ->whereIn('order_id', $orderIds)
            ->exists();
    }

    private function hasSerialCorrection(?string $customerPhone): bool
    {
        $orderIds = $this->orderIds($customerPhone);

        if ($orderIds->isEmpty()) {
            return false;
        }

        $incidentIds = Incident::query()
            ->whereIn('order_id', $orderIds)
            ->pluck('id');

        $requestSent = $incidentIds->isNotEmpty()
            && AuditLog::query()
                ->where('auditable_type', (new Incident)->getMorphClass())
                ->whereIn('auditable_id', $incidentIds)
                ->where('event', RequestCorrectSerialAuditService::EVENT_REQUEST_SENT)
                ->exists();

        if ($requestSent) {
            return true;
        }

        return AuditLog::query()
            ->where('auditable_type', (new Order)->getMorphClass())
            ->whereIn('auditable_id', $orderIds)
            ->where('event', 'serial.corrected_by_ira')
            ->exists();
    }

    private function activeDeviceCount(?string $customerPhone): int
    {
        if (! filled($customerPhone)) {
            return 0;
        }

        return Order::query()
            ->where('customer_phone', $customerPhone)
            ->where('status', OrderStatus::Active)
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '')
            ->distinct()
            ->count('serial_number');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function orderIds(?string $customerPhone): \Illuminate\Support\Collection
    {
        if (! filled($customerPhone)) {
            return collect();
        }

        return Order::query()
            ->where('customer_phone', $customerPhone)
            ->pluck('id');
    }
}
