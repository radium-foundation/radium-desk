@php
    $oldSettings = old('settings', []);
    $providerLabels = [
        'auto' => 'Auto (from server)',
        'polling' => 'Polling',
        'ably' => 'Ably',
        'reverb' => 'Reverb',
    ];
    $connectionStatus = $realtimeHealth['connection_status'] ?? 'unknown';
    $connectionLabel = match ($connectionStatus) {
        'connected' => 'Connected',
        'connecting' => 'Connecting',
        'polling' => 'Polling',
        'offline' => 'Offline',
        'disconnected' => 'Disconnected',
        default => 'Unknown',
    };
    $effectiveProvider = $realtimeHealth['effective_provider'] ?? 'polling';
    $effectiveProviderLabel = $providerLabels[$effectiveProvider] ?? ucfirst($effectiveProvider);
@endphp

<div class="card border-0 shadow-sm" id="realtime-settings-card">
    <div class="card-header bg-white py-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-broadcast text-primary"></i>
            <div>
                <h2 class="h6 mb-0">Realtime</h2>
                <p class="text-muted small mb-0">Dashboard live updates, transport provider, and polling fallback.</p>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="accordion" id="realtimeSettingsAccordion">
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#realtimeConfigurationSection" aria-expanded="true" aria-controls="realtimeConfigurationSection">
                        Configuration
                    </button>
                </h3>
                <div id="realtimeConfigurationSection" class="accordion-collapse collapse show" data-bs-parent="#realtimeSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small mb-3">Changes apply after users refresh their browser. Secrets (ABLY_KEY, REVERB_*) remain in environment variables.</p>
                        <div class="vstack gap-3">
                            @foreach($realtimeSettings as $setting)
                                @continue(($setting['key'] ?? '') === 'realtime.debug_mode' && ! auth()->user()?->hasRole(\Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN))

                                @php
                                    $inputId = 'setting_' . str_replace('.', '_', $setting['key']);
                                    $fieldName = 'settings[' . $setting['key'] . ']';
                                    $oldValue = is_array($oldSettings) && array_key_exists($setting['key'], $oldSettings)
                                        ? $oldSettings[$setting['key']]
                                        : $setting['value'];
                                @endphp

                                <div class="border rounded p-3 @if(! empty($setting['disabled'])) opacity-75 @endif">
                                    @if($setting['type'] === 'boolean')
                                        <div class="form-check form-switch mb-0">
                                            <input type="hidden" name="{{ $fieldName }}" value="{{ ! empty($setting['disabled']) ? (filter_var($oldValue, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : '0' }}">
                                            <input type="checkbox"
                                                   name="{{ $fieldName }}"
                                                   value="1"
                                                   id="{{ $inputId }}"
                                                   class="form-check-input @error('settings.' . $setting['key']) is-invalid @enderror"
                                                   @checked(filter_var($oldValue, FILTER_VALIDATE_BOOLEAN))
                                                   @disabled(! empty($setting['disabled']))>
                                            <label class="form-check-label fw-medium" for="{{ $inputId }}">
                                                {{ $setting['label'] }}
                                                @if($setting['key'] === 'realtime.debug_mode')
                                                    <span class="badge text-bg-warning ms-1">Superadmin</span>
                                                @endif
                                                @if(! empty($setting['disabled']))
                                                    <span class="badge text-bg-secondary ms-1">Coming Soon</span>
                                                @endif
                                            </label>
                                        </div>
                                    @elseif($setting['type'] === 'string' && is_array($setting['allowed']))
                                        <label class="form-label fw-medium" for="{{ $inputId }}">{{ $setting['label'] }}</label>
                                        <select name="{{ $fieldName }}"
                                                id="{{ $inputId }}"
                                                class="form-select @error('settings.' . $setting['key']) is-invalid @enderror"
                                                style="max-width: 20rem;"
                                                @disabled(! empty($setting['disabled']))>
                                            @foreach($setting['allowed'] as $option)
                                                <option value="{{ $option }}" @selected((string) $oldValue === (string) $option) @disabled($option === 'reverb')>
                                                    {{ $providerLabels[$option] ?? ucfirst($option) }}@if($option === 'reverb') (Coming Soon)@endif
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <label class="form-label fw-medium" for="{{ $inputId }}">{{ $setting['label'] }}</label>
                                        <div class="input-group" style="max-width: 16rem;">
                                            <input type="number"
                                                   name="{{ $fieldName }}"
                                                   id="{{ $inputId }}"
                                                   value="{{ $oldValue }}"
                                                   class="form-control @error('settings.' . $setting['key']) is-invalid @enderror"
                                                   @if($setting['min'] !== null) min="{{ $setting['min'] }}" @endif
                                                   @if($setting['max'] !== null) max="{{ $setting['max'] }}" @endif
                                                   @disabled(! empty($setting['disabled']))>
                                            @if($setting['unit'])
                                                <span class="input-group-text">{{ $setting['unit'] }}</span>
                                            @endif
                                        </div>
                                    @endif

                                    @if(! empty($setting['description']))
                                        <div class="form-text">{{ $setting['description'] }}</div>
                                    @endif
                                    @error('settings.' . $setting['key'])
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                    @if($setting['updated_at'])
                                        <div class="text-muted small mt-2">
                                            Last updated {{ $setting['updated_at']->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                            @if($setting['updated_by_name'])
                                                by {{ $setting['updated_by_name'] }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#realtimeHealthSection" aria-expanded="false" aria-controls="realtimeHealthSection">
                        Connection Status
                    </button>
                </h3>
                <div id="realtimeHealthSection" class="accordion-collapse collapse" data-bs-parent="#realtimeSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small mb-3">Read-only snapshot from the most recent dashboard client report.</p>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">Configured provider</dt>
                            <dd class="col-sm-8"><code>{{ $realtimeHealth['configured_provider'] ?? 'auto' }}</code></dd>

                            <dt class="col-sm-4">Current provider</dt>
                            <dd class="col-sm-8">{{ $effectiveProviderLabel }}</dd>

                            <dt class="col-sm-4">Current connection</dt>
                            <dd class="col-sm-8">
                                <span @class([
                                    'badge',
                                    'text-bg-success' => $connectionStatus === 'connected',
                                    'text-bg-info' => $connectionStatus === 'connecting',
                                    'text-bg-warning' => $connectionStatus === 'polling',
                                    'text-bg-secondary' => in_array($connectionStatus, ['unknown', 'offline'], true),
                                    'text-bg-danger' => $connectionStatus === 'disconnected',
                                ])>{{ $connectionLabel }}</span>
                            </dd>

                            <dt class="col-sm-4">Polling active</dt>
                            <dd class="col-sm-8">{{ ! empty($realtimeHealth['polling_active']) ? 'Yes' : 'No' }}</dd>

                            <dt class="col-sm-4">Last connected</dt>
                            <dd class="col-sm-8">
                                @if(! empty($realtimeHealth['last_connected_at']))
                                    {{ \Illuminate\Support\Carbon::parse($realtimeHealth['last_connected_at'])->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}
                                @else
                                    <span class="text-muted">Never reported</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4">Latest error</dt>
                            <dd class="col-sm-8">
                                @if(! empty($realtimeHealth['last_error']))
                                    <code class="text-danger">{{ $realtimeHealth['last_error'] }}</code>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4">Last disconnect reason</dt>
                            <dd class="col-sm-8">
                                @if(! empty($realtimeHealth['last_disconnect_reason']))
                                    <code>{{ $realtimeHealth['last_disconnect_reason'] }}</code>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </dd>

                            <dt class="col-sm-4">Last report</dt>
                            <dd class="col-sm-8">
                                @if(! empty($realtimeHealth['reported_at']))
                                    {{ \Illuminate\Support\Carbon::parse($realtimeHealth['reported_at'])->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </dd>
                        </dl>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-realtime-test
                                    data-url="{{ route('admin.system-settings.realtime.test') }}">
                                Test Realtime Connection
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-realtime-force-reconnect
                                    data-url="{{ route('admin.system-settings.realtime.force-reconnect') }}">
                                Force Reconnect
                            </button>
                            <form method="POST" action="{{ route('admin.system-settings.realtime.reset-status') }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    Reset Connection Status
                                </button>
                            </form>
                        </div>
                        <div class="small mt-2 d-none" data-realtime-admin-message aria-live="polite"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
