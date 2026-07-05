@php
    use App\Support\AppDateFormatter;

    $displayValue = fn (?string $value) => filled($value) ? $value : 'Not Available';
    $lastPayment = $healthCard['last_payment'] ?? null;
    $lastInteractionAt = $healthCard['last_interaction_at'] ?? null;
@endphp

<section class="customer-360-health-card" data-customer-360-section="health-card" aria-labelledby="customer-360-health-heading">
    <div class="customer-360-health-card-header">
        <h2 class="customer-360-health-card-name" id="customer-360-health-heading">
            {{ $displayValue($healthCard['name'] ?? null) }}
        </h2>
    </div>

    <dl class="customer-360-health-grid">
        <div class="customer-360-health-item">
            <dt><i class="bi bi-telephone" aria-hidden="true"></i> Phone</dt>
            <dd>
                <x-customer-360-inline-copy
                    :value="$healthCard['phone'] ?? null"
                    label="Customer Phone"
                    copy-key="phone"
                />
            </dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-envelope" aria-hidden="true"></i> Email</dt>
            <dd>
                <x-customer-360-inline-copy
                    :value="$healthCard['email'] ?? null"
                    label="Customer Email"
                    copy-key="email"
                />
            </dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-shield-check" aria-hidden="true"></i> Warranty</dt>
            <dd>{{ $displayValue($healthCard['warranty_status'] ?? null) }}</dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-tools" aria-hidden="true"></i> Active Cases</dt>
            <dd>{{ $healthCard['active_service_cases'] ?? 0 }}</dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-credit-card" aria-hidden="true"></i> Last Payment</dt>
            <dd>
                @if($lastPayment)
                    <span class="customer-360-health-value">{{ $lastPayment['label'] }}</span>
                    <time class="customer-360-health-meta"
                          datetime="{{ $lastPayment['occurred_at']->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastPayment['occurred_at']) }}">
                        {{ AppDateFormatter::timelineRelative($lastPayment['occurred_at']) }}
                    </time>
                @else
                    Not Available
                @endif
            </dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-whatsapp" aria-hidden="true"></i> Last WhatsApp</dt>
            <dd>
                @include('customer-360.partials.health-card-communication', [
                    'communication' => $healthCard['last_whatsapp'] ?? [],
                ])
            </dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-clock-history" aria-hidden="true"></i> Last Interaction</dt>
            <dd>
                @if($lastInteractionAt)
                    <time datetime="{{ $lastInteractionAt->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastInteractionAt) }}">
                        {{ AppDateFormatter::timelineRelative($lastInteractionAt) }}
                    </time>
                @else
                    Not Available
                @endif
            </dd>
        </div>
        <div class="customer-360-health-item customer-360-health-item--placeholder">
            <dt><i class="bi bi-telephone-outbound" aria-hidden="true"></i> Last Call</dt>
            <dd><span class="customer-360-health-placeholder">Coming soon</span></dd>
        </div>
        <div class="customer-360-health-item">
            <dt><i class="bi bi-envelope-open" aria-hidden="true"></i> Last Email</dt>
            <dd>
                @include('customer-360.partials.health-card-communication', [
                    'communication' => $healthCard['last_email'] ?? [],
                ])
            </dd>
        </div>
    </dl>
</section>
