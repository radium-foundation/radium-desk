<?php

namespace App\Services\Customer360;

use App\Enums\OperationsHealthStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\Operations\OperationsRadiumBoxHealthService;
use App\Services\Operations\OperationsIntegrationHealthService;
use App\Services\Interakt\InteraktTemplateConfigurationValidator;
use App\Enums\WhatsAppTemplate;
use Illuminate\Support\Facades\Cache;

class Customer360OperationsHealthService
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealthService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly InteraktTemplateConfigurationValidator $interaktTemplateConfigurationValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forIncident(Incident $incident): array
    {
        $cacheKey = 'customer360:operations-health:incident:'.$incident->id;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $incident->loadMissing('supportAppointments');
        $order = $incident->order;

        if ($order === null) {
            return [
                'radiumbox' => [
                    'status' => 'idle',
                    'pending' => false,
                    'failed' => false,
                    'recovery_running' => false,
                    'platform_pending' => 0,
                    'platform_failed' => 0,
                ],
                'email' => [
                    'status' => 'unknown',
                    'status_label' => 'Unavailable',
                    'detail' => 'Monitoring data unavailable.',
                ],
                'whatsapp' => [
                    'status' => 'unknown',
                    'status_label' => 'Unavailable',
                    'detail' => 'Monitoring data unavailable.',
                ],
                'appointments' => [
                    'status' => 'healthy',
                    'status_label' => 'Healthy',
                    'detail' => 'Booking links available for support appointments.',
                ],
                'whatsapp_flow' => $this->whatsappFlowHealth($incident),
            ];
        }

        $widget = $this->build($order, $incident);
        Cache::put($cacheKey, $widget, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $widget;
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        return $this->build($order);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Order $order, ?Incident $incident = null): array
    {
        $radiumBox = $this->radiumBoxHealthService->widget();
        $integrations = collect($this->integrationHealthService->cards());

        return [
            'radiumbox' => [
                'status' => $this->radiumBoxStatus($order),
                'pending' => $order->radiumbox_sync_status === RadiumBoxEnrichmentSyncStatus::Pending,
                'failed' => $order->radiumbox_sync_status === RadiumBoxEnrichmentSyncStatus::Failed,
                'recovery_running' => $order->radiumbox_sync_status === RadiumBoxEnrichmentSyncStatus::Pending,
                'platform_pending' => $radiumBox['pending_syncs'] ?? 0,
                'platform_failed' => $radiumBox['failed_syncs'] ?? 0,
            ],
            'email' => $this->channelHealth($integrations->firstWhere('key', 'zeptomail')),
            'whatsapp' => array_merge(
                $this->channelHealth($integrations->firstWhere('key', 'interakt')),
                [
                    'template_diagnostics' => $this->interaktTemplateConfigurationValidator
                        ->diagnosticsFor(WhatsAppTemplate::RequestSerialNumber),
                ],
            ),
            'appointments' => [
                'status' => 'healthy',
                'status_label' => 'Healthy',
                'detail' => 'Booking links available for support appointments.',
            ],
            'whatsapp_flow' => $this->whatsappFlowHealth($incident),
        ];
    }

    /**
     * @return array{status: string, status_label: string, detail: string}
     */
    private function whatsappFlowHealth(?Incident $incident): array
    {
        $hasAppointment = $incident !== null && $incident->supportAppointments->isNotEmpty();

        if ($hasAppointment) {
            return [
                'status' => 'ready',
                'status_label' => 'Ready',
                'detail' => 'WhatsApp Flow context can be generated for this case.',
            ];
        }

        return [
            'status' => OperationsHealthStatus::NotConfigured->value,
            'status_label' => OperationsHealthStatus::NotConfigured->label(),
            'detail' => 'Schedule a support appointment to prepare WhatsApp Flow.',
        ];
    }

    private function radiumBoxStatus(Order $order): string
    {
        return match ($order->radiumbox_sync_status) {
            RadiumBoxEnrichmentSyncStatus::Failed => 'failed',
            RadiumBoxEnrichmentSyncStatus::Pending => 'pending',
            RadiumBoxEnrichmentSyncStatus::Synced => 'healthy',
            default => 'idle',
        };
    }

    /**
     * @param  array<string, mixed>|null  $card
     * @return array{status: string, status_label: string, detail: string}
     */
    private function channelHealth(?array $card): array
    {
        if ($card === null) {
            return [
                'status' => 'unknown',
                'status_label' => 'Unavailable',
                'detail' => 'Monitoring data unavailable.',
            ];
        }

        $status = OperationsHealthStatus::tryFrom((string) ($card['status'] ?? ''))
            ?? OperationsHealthStatus::Warning;

        return [
            'status' => $status->value,
            'status_label' => (string) ($card['status_label'] ?? $status->label()),
            'detail' => (string) ($card['detail'] ?? ''),
        ];
    }
}
