@php
    $emailSettings = $groupedSettings['email'] ?? collect();
    $whatsappSettings = $groupedSettings['whatsapp'] ?? collect();
    $telegramSettings = $groupedSettings['telegram'] ?? collect();
    $outboxSettings = $groupedSettings['outbox'] ?? collect();

    $emailEnabled = $emailSettings->isNotEmpty()
        ? filter_var($emailSettings->first()['value'] ?? false, FILTER_VALIDATE_BOOLEAN)
        : filter_var(config('mail.enabled', true), FILTER_VALIDATE_BOOLEAN);
    $whatsappEnabled = $whatsappSettings->isNotEmpty()
        ? filter_var($whatsappSettings->first()['value'] ?? false, FILTER_VALIDATE_BOOLEAN)
        : false;
    $telephonyEnabled = filter_var(config('bonvoice.click_to_call.enabled', false), FILTER_VALIDATE_BOOLEAN);
    $telegramEnabled = $telegramSettings->isNotEmpty()
        ? filter_var($telegramSettings->first()['value'] ?? false, FILTER_VALIDATE_BOOLEAN)
        : false;

    $featureFlagSettings = collect($groupedSettings)->flatten(1)
        ->filter(fn ($s) => ($s['type'] ?? '') === 'boolean' && empty($s['disabled']));
    $hybridKeys = collect($performanceHybridRealtimeSettings)->pluck('key')->all();
    $featureFlagSettings = $featureFlagSettings->reject(fn ($s) => in_array($s['key'], $hybridKeys, true));
    $enabledFlags = $featureFlagSettings->filter(fn ($s) => filter_var($s['value'] ?? false, FILTER_VALIDATE_BOOLEAN))->count();
    $totalFlags = $featureFlagSettings->count();

    $mailDriver = config('mail.default', 'log');
    $smtpConnected = $mailDriver !== 'log' && filter_var(config('mail.enabled', true), FILTER_VALIDATE_BOOLEAN);
@endphp

<x-system-settings.section
    id="section-operational-center"
    icon="bi-lightning-charge"
    title="Operational Center"
    description="Integrations, channels, and feature controls in one place."
>
    <div class="settings-center-op-grid mb-4">
        <x-settings-center.operational-hub-card
            icon="mail"
            title="Email"
            :status="$smtpConnected ? 'Connected' : 'Not configured'"
            :status-tone="$smtpConnected ? 'success' : 'warning'"
            :description="$smtpConnected ? 'SMTP connected via '.strtoupper($mailDriver) : 'Email delivery is not fully configured.'"
            primary-label="Configure"
            :primary-href="'#operational-email'"
            secondary-label="Test Email"
        />
        <x-settings-center.operational-hub-card
            icon="message-circle"
            title="WhatsApp"
            :status="$whatsappEnabled ? 'Connected' : 'Disabled'"
            :status-tone="$whatsappEnabled ? 'success' : 'neutral'"
            description="Interakt and WhatsApp API integration controls."
            primary-label="Configure"
            :primary-href="'#operational-whatsapp'"
            secondary-label="Test Message"
        />
        <x-settings-center.operational-hub-card
            icon="phone-call"
            title="Telephony"
            :status="$telephonyEnabled ? 'Connected' : 'Offline'"
            :status-tone="$telephonyEnabled ? 'success' : 'danger'"
            description="Bonvoice click-to-call and telephony webhooks."
            primary-label="Configure"
            :primary-href="'#operational-telephony'"
            secondary-label="Test Call"
        />
        <x-settings-center.operational-hub-card
            icon="flag"
            title="Feature Flags"
            :status="$enabledFlags.' Enabled'"
            status-tone="success"
            description="Runtime feature toggles across the platform."
            primary-label="Configure"
            :primary-href="'#operational-feature-flags'"
        />
        <x-settings-center.operational-hub-card
            icon="plug-zap"
            title="Integrations"
            status="4 Services"
            status-tone="success"
            description="Cashfree, Gmail, Telegram, and webhook connectors."
            primary-label="Manage"
            :primary-href="'#operational-integrations'"
        />
    </div>

    <div class="settings-center-op-panels">
        <x-system-settings.card id="operational-email" title="Email Configuration" description="Outbound email API and delivery controls." class="mb-3">
            @if($emailSettings->isNotEmpty())
                <div class="system-settings-rows">
                    @foreach($emailSettings as $setting)
                        <x-system-settings.setting-row
                            :setting="$setting"
                            :high-impact="true"
                            impact-message="Disabling Email API will stop all outbound email delivery."
                            :affected-modules="['Email', 'Outbox']"
                        />
                    @endforeach
                </div>
            @else
                <p class="text-muted mb-0">Email channel settings are managed via environment configuration.</p>
            @endif
        </x-system-settings.card>

        <x-system-settings.card id="operational-whatsapp" title="WhatsApp Configuration" description="WhatsApp API and automation feature flags." class="mb-3">
            @if($whatsappSettings->isNotEmpty())
                <div class="system-settings-rows">
                    @foreach($whatsappSettings as $setting)
                        <x-system-settings.setting-row
                            :setting="$setting"
                            :high-impact="true"
                            impact-message="Disabling WhatsApp may affect outbound messaging."
                            :affected-modules="['WhatsApp', 'Outbox']"
                        />
                    @endforeach
                </div>
            @endif
        </x-system-settings.card>

        <x-system-settings.card id="operational-telephony" title="Telephony" description="Bonvoice click-to-call configuration (environment-based)." class="mb-3">
            <div class="settings-center-details-grid">
                <div class="settings-center-detail">
                    <span class="settings-center-detail__label">Provider</span>
                    <span class="settings-center-detail__value">Bonvoice</span>
                </div>
                <div class="settings-center-detail">
                    <span class="settings-center-detail__label">Click to call</span>
                    <span class="settings-center-detail__value">
                        <span @class([
                            'settings-center-status-pill settings-center-status-pill--sm',
                            'settings-center-status-pill--success' => $telephonyEnabled,
                            'settings-center-status-pill--danger' => ! $telephonyEnabled,
                        ])>{{ $telephonyEnabled ? 'Enabled' : 'Disabled' }}</span>
                    </span>
                </div>
                <div class="settings-center-detail">
                    <span class="settings-center-detail__label">API base URL</span>
                    <span class="settings-center-detail__value"><code>{{ config('bonvoice.click_to_call.base_url') }}</code></span>
                </div>
            </div>
            @if($telegramSettings->isNotEmpty())
                <div class="mt-3 pt-3 border-top">
                    <h4 class="h6 mb-3">Telegram (related channel)</h4>
                    <div class="system-settings-rows">
                        @foreach($telegramSettings as $setting)
                            <x-system-settings.setting-row
                                :setting="$setting"
                                :high-impact="true"
                                impact-message="Disabling Telegram API will stop Telegram outbound messages."
                                :affected-modules="['Telegram', 'Outbox']"
                            />
                        @endforeach
                    </div>
                </div>
            @endif
        </x-system-settings.card>

        <x-system-settings.card id="operational-feature-flags" title="Feature Flags" description="Enable or disable platform capabilities." class="mb-3">
            <div class="system-settings-rows">
                @foreach($featureFlagSettings as $setting)
                    <x-system-settings.setting-row :setting="$setting" />
                @endforeach
            </div>
        </x-system-settings.card>

        <x-system-settings.card id="operational-integrations" title="Integrations" description="Connected third-party services.">
            <div class="settings-center-integration-list">
                @foreach([
                    ['name' => 'Cashfree', 'status' => 'Connected', 'tone' => 'success'],
                    ['name' => 'Gmail', 'status' => $smtpConnected ? 'Connected' : 'Not configured', 'tone' => $smtpConnected ? 'success' : 'warning'],
                    ['name' => 'Telegram', 'status' => $telegramEnabled ? 'Connected' : 'Disabled', 'tone' => $telegramEnabled ? 'success' : 'neutral'],
                    ['name' => 'Webhook', 'status' => 'Healthy', 'tone' => 'success'],
                ] as $integration)
                    <div class="settings-center-integration-list__item">
                        <span class="settings-center-integration-list__name">{{ $integration['name'] }}</span>
                        <span @class([
                            'settings-center-status-pill settings-center-status-pill--sm',
                            'settings-center-status-pill--'.$integration['tone'],
                        ])>{{ $integration['status'] }}</span>
                    </div>
                @endforeach
            </div>
            @if($outboxSettings->isNotEmpty())
                <div class="mt-3 pt-3 border-top">
                    <h4 class="h6 mb-3">Outbox processing</h4>
                    <div class="system-settings-rows">
                        @foreach($outboxSettings as $setting)
                            <x-system-settings.setting-row
                                :setting="$setting"
                                :high-impact="true"
                                impact-message="Disabling outbox processing will stop queued outbound messages."
                                :affected-modules="['Outbox', 'Email', 'WhatsApp']"
                            />
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mt-3">
                <a href="{{ route('admin.platform.index') }}#platform-zone-integration_health" class="btn btn-sm btn-outline-secondary">
                    Open Integration Health
                </a>
            </div>
        </x-system-settings.card>
    </div>
</x-system-settings.section>
