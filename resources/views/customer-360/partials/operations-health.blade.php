@props([
    'health' => [],
])

<section class="customer-360-operations-health" aria-labelledby="customer-360-operations-health-heading">
    <h4 class="customer-360-section-title" id="customer-360-operations-health-heading">Operations Health</h4>
    <div class="customer-360-operations-health-grid">
        <div class="customer-360-ops-health-card">
            <span class="customer-360-ops-health-label">RadiumBox</span>
            <strong class="customer-360-ops-health-value">{{ ucfirst($health['radiumbox']['status'] ?? 'idle') }}</strong>
            <span class="customer-360-ops-health-detail">
                @if($health['radiumbox']['pending'] ?? false)
                    Pending
                @elseif($health['radiumbox']['failed'] ?? false)
                    Failed
                @elseif($health['radiumbox']['recovery_running'] ?? false)
                    Recovery running
                @else
                    Synced
                @endif
            </span>
        </div>
        <div class="customer-360-ops-health-card">
            <span class="customer-360-ops-health-label">Email</span>
            <strong class="customer-360-ops-health-value">{{ $health['email']['status_label'] ?? 'Unavailable' }}</strong>
            <span class="customer-360-ops-health-detail">{{ $health['email']['detail'] ?? '' }}</span>
        </div>
        <div class="customer-360-ops-health-card">
            <span class="customer-360-ops-health-label">WhatsApp</span>
            <strong class="customer-360-ops-health-value">{{ $health['whatsapp']['status_label'] ?? 'Unavailable' }}</strong>
            <span class="customer-360-ops-health-detail">{{ $health['whatsapp']['detail'] ?? '' }}</span>
            @php($templateDiagnostics = $health['whatsapp']['template_diagnostics'] ?? [])
            @if($templateDiagnostics !== [])
                <div class="customer-360-ops-health-diagnostics mt-2">
                    <div class="customer-360-ops-health-detail">
                        Interakt Template:
                        @if($templateDiagnostics['template_missing'] ?? false)
                            ❌ Template not configured
                        @else
                            {{ $templateDiagnostics['template_name'] ?? 'Not configured' }}
                        @endif
                    </div>
                    <div class="customer-360-ops-health-detail">
                        Language: {{ $templateDiagnostics['language_code'] ?? 'Not configured' }}
                        @if(filled($templateDiagnostics['language_fallback_warning'] ?? null))
                            <span class="text-warning">⚠ Using fallback language "{{ \App\Services\Interakt\InteraktTemplateConfigurationValidator::DEFAULT_LANGUAGE }}"</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
        <div class="customer-360-ops-health-card">
            <span class="customer-360-ops-health-label">Appointments</span>
            <strong class="customer-360-ops-health-value">{{ $health['appointments']['status_label'] ?? 'Healthy' }}</strong>
            <span class="customer-360-ops-health-detail">{{ $health['appointments']['detail'] ?? '' }}</span>
        </div>
        <div class="customer-360-ops-health-card">
            <span class="customer-360-ops-health-label">WhatsApp Flow</span>
            <strong class="customer-360-ops-health-value">{{ $health['whatsapp_flow']['status_label'] ?? 'Not Configured' }}</strong>
            <span class="customer-360-ops-health-detail">{{ $health['whatsapp_flow']['detail'] ?? '' }}</span>
        </div>
    </div>
</section>
