@props([
    'healthCard' => [],
    'activeServices' => [],
])

@php
    use App\Support\AppDateFormatter;

    $warranty = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? ($healthCard['warranty_status'] ?? null);
    $amc = collect($activeServices)->firstWhere('label', 'AMC')['status'] ?? null;
    $lastPayment = $healthCard['last_payment'] ?? null;
    $lastCall = $healthCard['last_call'] ?? null;
    $lastWhatsapp = $healthCard['last_whatsapp'] ?? [];
    $hasWhatsapp = in_array($lastWhatsapp['status'] ?? 'not_sent', ['sent', 'failed'], true);
    $hasEmail = filled($healthCard['email'] ?? null);
    $hasPhone = filled($healthCard['phone'] ?? null);
@endphp

<section {{ $attributes->merge(['class' => 'c360-customer-snapshot']) }}
         data-customer-360-section="health-card"
         aria-labelledby="c360-customer-snapshot-heading">
    <h2 class="c360-customer-snapshot-heading" id="c360-customer-snapshot-heading">Customer snapshot</h2>

    <dl class="c360-customer-snapshot-grid">
        @if($hasPhone)
            <div class="c360-customer-snapshot-item">
                <dt><i class="bi bi-telephone" aria-hidden="true"></i> Phone</dt>
                <dd>
                    <x-customer-360-inline-copy
                        :value="$healthCard['phone']"
                        label="Customer Phone"
                        copy-key="phone"
                    />
                </dd>
            </div>
        @endif

        @if($hasEmail)
            <div class="c360-customer-snapshot-item">
                <dt><i class="bi bi-envelope" aria-hidden="true"></i> Email</dt>
                <dd>
                    <x-customer-360-inline-copy
                        :value="$healthCard['email']"
                        label="Customer Email"
                        copy-key="email"
                    />
                </dd>
            </div>
        @endif

        @if($hasWhatsapp)
            <div class="c360-customer-snapshot-item">
                <dt><i class="bi bi-whatsapp" aria-hidden="true"></i> Last WhatsApp</dt>
                <dd>
                    @include('customer-360.partials.health-card-communication', ['communication' => $lastWhatsapp])
                </dd>
            </div>
        @endif

        @if($lastCall)
            <div class="c360-customer-snapshot-item">
                <dt><i class="bi bi-telephone-outbound" aria-hidden="true"></i> Last call</dt>
                <dd>
                    <span class="c360-customer-snapshot-value">{{ $lastCall['status_label'] }}</span>
                    <time class="c360-customer-snapshot-meta"
                          datetime="{{ $lastCall['occurred_at']->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastCall['occurred_at']) }}">
                        {{ $lastCall['occurred_at_label'] }}
                    </time>
                </dd>
            </div>
        @endif

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-shield-check" aria-hidden="true"></i> Warranty</dt>
            <dd>
                @if(filled($warranty) && $warranty !== 'Not Available')
                    {{ $warranty }}
                @else
                    <x-c360.unavailable-pill />
                @endif
            </dd>
        </div>

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> AMC</dt>
            <dd>
                @if(filled($amc) && $amc !== 'Not Available')
                    {{ $amc }}
                @else
                    <x-c360.unavailable-pill />
                @endif
            </dd>
        </div>

        @if($lastPayment)
            <div class="c360-customer-snapshot-item">
                <dt><i class="bi bi-credit-card" aria-hidden="true"></i> Payment</dt>
                <dd>
                    <span class="c360-customer-snapshot-value">{{ $lastPayment['label'] }}</span>
                    <time class="c360-customer-snapshot-meta"
                          datetime="{{ $lastPayment['occurred_at']->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastPayment['occurred_at']) }}">
                        {{ AppDateFormatter::timelineRelative($lastPayment['occurred_at']) }}
                    </time>
                </dd>
            </div>
        @endif

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-tools" aria-hidden="true"></i> Active cases</dt>
            <dd class="c360-customer-snapshot-value">{{ $healthCard['active_service_cases'] ?? 0 }}</dd>
        </div>
    </dl>
</section>
