@php
    $oldSettings = old('settings', []);
    $currentProfile = is_array($oldSettings) && array_key_exists('performance.profile', $oldSettings)
        ? (string) $oldSettings['performance.profile']
        : $performanceProfile;
    $isManualProfile = $currentProfile === 'manual';
    $profilePresets = collect($performanceProfiles)->mapWithKeys(fn (array $profile, string $key): array => [
        $key => $profile['values'] ?? [],
    ]);
@endphp

<div class="card border-0 shadow-sm"
     id="performance-settings-card"
     data-performance-settings
     data-profile-presets='@json($profilePresets)'>
    <div class="card-header bg-white py-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-speedometer2 text-primary"></i>
            <div>
                <h2 class="h6 mb-0">Performance</h2>
                <p class="text-muted small mb-0">Runtime performance controls — polling, profiles, and hybrid realtime.</p>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="accordion" id="performanceSettingsAccordion">
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#performanceProfileSection" aria-expanded="true" aria-controls="performanceProfileSection">
                        Performance Profile
                    </button>
                </h3>
                <div id="performanceProfileSection" class="accordion-collapse collapse show" data-bs-parent="#performanceSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small">Select a preset to auto-populate polling values, or choose Manual to customize each interval.</p>
                        <div class="row g-3">
                            @foreach($performanceProfiles as $profileKey => $profile)
                                @php
                                    $profileInputId = 'performance_profile_' . $profileKey;
                                @endphp
                                <div class="col-md-6 col-xl-3">
                                    <div class="form-check border rounded p-3 h-100 @if($currentProfile === $profileKey) border-primary bg-light @endif">
                                        <input type="radio"
                                               name="settings[performance.profile]"
                                               value="{{ $profileKey }}"
                                               id="{{ $profileInputId }}"
                                               class="form-check-input"
                                               data-performance-profile-option
                                               @checked($currentProfile === $profileKey)>
                                        <label class="form-check-label fw-medium" for="{{ $profileInputId }}">
                                            {{ $profile['label'] }}
                                            @if(! empty($profile['recommended']))
                                                <span class="badge text-bg-primary ms-1">Recommended</span>
                                            @endif
                                        </label>
                                        @if(! empty($profile['description']))
                                            <div class="form-text">{{ $profile['description'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @error('settings.performance.profile')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#performancePollingSection" aria-expanded="false" aria-controls="performancePollingSection">
                        Polling Configuration
                    </button>
                </h3>
                <div id="performancePollingSection" class="accordion-collapse collapse" data-bs-parent="#performanceSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small mb-3">Intervals take effect after users refresh their browser. Polling fields are editable only in Manual mode.</p>
                        <div class="vstack gap-3">
                            @foreach($performancePollingSettings as $setting)
                                @php
                                    $inputId = 'setting_' . str_replace('.', '_', $setting['key']);
                                    $fieldName = 'settings[' . $setting['key'] . ']';
                                    $oldValue = is_array($oldSettings) && array_key_exists($setting['key'], $oldSettings)
                                        ? $oldSettings[$setting['key']]
                                        : $setting['value'];
                                    $pollingDisabled = ! $isManualProfile || ! empty($setting['disabled']);
                                @endphp
                                <div class="border rounded p-3 @if(! empty($setting['disabled'])) opacity-75 @endif">
                                    <label class="form-label fw-medium" for="{{ $inputId }}">{{ $setting['label'] }}</label>
                                    <div class="input-group" style="max-width: 16rem;">
                                        <input type="number"
                                               name="{{ $fieldName }}"
                                               id="{{ $inputId }}"
                                               value="{{ $oldValue }}"
                                               class="form-control @error('settings.' . $setting['key']) is-invalid @enderror"
                                               data-performance-polling-input
                                               data-setting-key="{{ $setting['key'] }}"
                                               @if($setting['min'] !== null) min="{{ $setting['min'] }}" @endif
                                               @if($setting['max'] !== null) max="{{ $setting['max'] }}" @endif
                                               @readonly($pollingDisabled)
                                               @class(['bg-light' => $pollingDisabled])>
                                        @if($setting['unit'])
                                            <span class="input-group-text">{{ $setting['unit'] }}</span>
                                        @endif
                                    </div>
                                    @if(! empty($setting['description']))
                                        <div class="form-text">{{ $setting['description'] }}</div>
                                    @endif
                                    @if($setting['min'] !== null && $setting['max'] !== null)
                                        <div class="text-muted small mt-1">
                                            Min {{ number_format($setting['min']) }}
                                            · Max {{ number_format($setting['max']) }}
                                            @if($setting['recommended'] !== null)
                                                · Recommended {{ number_format($setting['recommended']) }} {{ $setting['unit'] }}
                                            @endif
                                        </div>
                                    @endif
                                    @if(! empty($setting['disabled']))
                                        <input type="hidden" name="{{ $fieldName }}" value="{{ $oldValue }}">
                                        <span class="badge text-bg-secondary mt-1">Future</span>
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
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#performanceNotificationDeliverySection" aria-expanded="false" aria-controls="performanceNotificationDeliverySection">
                        Notification Delivery
                    </button>
                </h3>
                <div id="performanceNotificationDeliverySection" class="accordion-collapse collapse" data-bs-parent="#performanceSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small mb-3">Configure how realtime notifications are delivered to operators. Requires Desktop Notifications or Operator Alerts hybrid realtime features.</p>
                        <div class="vstack gap-3">
                            @foreach($performanceNotificationSettings as $setting)
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
                                                   class="form-check-input"
                                                   @checked(filter_var($oldValue, FILTER_VALIDATE_BOOLEAN))
                                                   @disabled(! empty($setting['disabled']))>
                                            <label class="form-check-label fw-medium" for="{{ $inputId }}">
                                                {{ $setting['label'] }}
                                                @if(! empty($setting['disabled']))
                                                    <span class="badge text-bg-secondary ms-1">Future</span>
                                                @endif
                                            </label>
                                        </div>
                                    @elseif($setting['type'] === 'string' && is_array($setting['allowed']))
                                        <label class="form-label fw-medium" for="{{ $inputId }}">{{ $setting['label'] }}</label>
                                        <select name="{{ $fieldName }}" id="{{ $inputId }}" class="form-select" style="max-width: 16rem;" @disabled(! empty($setting['disabled']))>
                                            @foreach($setting['allowed'] as $option)
                                                <option value="{{ $option }}" @selected((string) $oldValue === (string) $option)>{{ ucfirst($option) }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <label class="form-label fw-medium" for="{{ $inputId }}">{{ $setting['label'] }}</label>
                                        <div class="input-group" style="max-width: 16rem;">
                                            <input type="number"
                                                   name="{{ $fieldName }}"
                                                   id="{{ $inputId }}"
                                                   value="{{ $oldValue }}"
                                                   class="form-control"
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
                                    @if(! empty($setting['disabled']))
                                        <input type="hidden" name="{{ $fieldName }}" value="{{ $oldValue }}">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#performanceHybridRealtimeSection" aria-expanded="false" aria-controls="performanceHybridRealtimeSection">
                        Hybrid Realtime
                    </button>
                </h3>
                <div id="performanceHybridRealtimeSection" class="accordion-collapse collapse" data-bs-parent="#performanceSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small mb-3">Opt-in Reverb features for live updates. Polling remains the safety net when disabled.</p>
                        <div class="vstack gap-3">
                            @foreach($performanceHybridRealtimeSettings as $setting)
                                @php
                                    $inputId = 'setting_' . str_replace('.', '_', $setting['key']);
                                    $fieldName = 'settings[' . $setting['key'] . ']';
                                    $oldValue = is_array($oldSettings) && array_key_exists($setting['key'], $oldSettings)
                                        ? $oldSettings[$setting['key']]
                                        : $setting['value'];
                                @endphp
                                <div class="border rounded p-3 @if(! empty($setting['disabled'])) opacity-75 @endif">
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
                                            @if(! empty($setting['disabled']))
                                                <span class="badge text-bg-secondary ms-1">Coming Soon</span>
                                            @endif
                                        </label>
                                    </div>
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
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#performanceHealthSection" aria-expanded="false" aria-controls="performanceHealthSection">
                        Live Health
                    </button>
                </h3>
                <div id="performanceHealthSection" class="accordion-collapse collapse" data-bs-parent="#performanceSettingsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted small mb-3">Read-only snapshot computed at page load. Interval changes apply after users refresh.</p>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">Performance profile</dt>
                            <dd class="col-sm-8">{{ ucwords(str_replace('_', ' ', $performanceHealth['performance_profile'])) }}</dd>

                            <dt class="col-sm-4">Dashboard polling</dt>
                            <dd class="col-sm-8">{{ number_format($performanceHealth['dashboard_poll_interval_ms']) }} ms</dd>

                            <dt class="col-sm-4">Notification polling</dt>
                            <dd class="col-sm-8">{{ number_format($performanceHealth['notification_poll_interval_ms']) }} ms</dd>

                            <dt class="col-sm-4">Broadcast driver</dt>
                            <dd class="col-sm-8"><code>{{ $performanceHealth['broadcast_driver'] }}</code></dd>

                            <dt class="col-sm-4">Dashboard live mode</dt>
                            <dd class="col-sm-8"><code>{{ $performanceHealth['dashboard_live_mode'] }}</code></dd>

                            <dt class="col-sm-4">Queue size (pending)</dt>
                            <dd class="col-sm-8">{{ number_format($performanceHealth['queue_pending_jobs']) }}</dd>

                            <dt class="col-sm-4">Failed jobs</dt>
                            <dd class="col-sm-8">{{ number_format($performanceHealth['failed_jobs']) }}</dd>

                            <dt class="col-sm-4">CPU load</dt>
                            <dd class="col-sm-8">
                                @if($performanceHealth['cpu_load']['available'])
                                    {{ $performanceHealth['cpu_load']['load'] }}
                                @else
                                    Unavailable
                                @endif
                            </dd>

                            <dt class="col-sm-4">Memory</dt>
                            <dd class="col-sm-8">
                                {{ $performanceHealth['memory']['current'] }} current · {{ $performanceHealth['memory']['peak'] }} peak
                            </dd>

                            <dt class="col-sm-4">WebSocket status</dt>
                            <dd class="col-sm-8">{{ $performanceHealth['websocket_status'] }}</dd>

                            <dt class="col-sm-4">Hybrid Realtime features</dt>
                            <dd class="col-sm-8">
                                <ul class="list-unstyled mb-0">
                                    @foreach($performanceHealth['hybrid_realtime_features'] as $feature)
                                        <li>
                                            {{ $feature['label'] }}:
                                            @if(! $feature['wired'])
                                                <span class="text-muted">Not wired</span>
                                            @elseif($feature['enabled'])
                                                <span class="text-success">On</span>
                                            @else
                                                <span class="text-muted">Off</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
