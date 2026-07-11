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
    $lastEmail = $healthCard['last_email'] ?? [];
@endphp

<section {{ $attributes->merge(['class' => 'c360-customer-snapshot']) }}
         data-customer-360-section="health-card"
         aria-labelledby="c360-customer-snapshot-heading">
    <h2 class="c360-customer-snapshot-heading" id="c360-customer-snapshot-heading">Customer Snapshot</h2>

    <dl class="c360-customer-snapshot-grid">
        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-telephone" aria-hidden="true"></i> Phone</dt>
            <dd>
                @if(filled($healthCard['phone'] ?? null))
                    <x-customer-360-inline-copy
                        :value="$healthCard['phone']"
                        label="Customer Phone"
                        copy-key="phone"
                    />
                @else
                    <x-c360.unavailable-pill />
                @endif
            </dd>
        </div>

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-envelope" aria-hidden="true"></i> Email</dt>
            <dd>
                @if(filled($healthCard['email'] ?? null))
                    <x-customer-360-inline-copy
                        :value="$healthCard['email']"
                        label="Customer Email"
                        copy-key="email"
                    />
                @else
                    <span class="c360-customer-snapshot-empty">
                        <i class="bi bi-envelope-x" aria-hidden="true"></i>
                        <span>No email on file</span>
                    </span>
                @endif
            </dd>
        </div>

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-whatsapp" aria-hidden="true"></i> WhatsApp</dt>
            <dd>
                @if(in_array($lastWhatsapp['status'] ?? 'not_sent', ['sent', 'failed'], true))
                    @include('customer-360.partials.health-card-communication', ['communication' => $lastWhatsapp])
                @else
                    <span class="c360-customer-snapshot-empty">
                        <i class="bi bi-whatsapp" aria-hidden="true"></i>
                        <span>No WhatsApp sent</span>
                    </span>
                @endif
            </dd>
        </div>

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-telephone-outbound" aria-hidden="true"></i> Last Call</dt>
            <dd>
                @if($lastCall)
                    <span class="c360-customer-snapshot-value">{{ $lastCall['status_label'] }}</span>
                    <time class="c360-customer-snapshot-meta"
                          datetime="{{ $lastCall['occurred_at']->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastCall['occurred_at']) }}">
                        {{ $lastCall['occurred_at_label'] }}
                    </time>
                @else
                    <span class="c360-customer-snapshot-empty">
                        <i class="bi bi-telephone-x" aria-hidden="true"></i>
                        <span>No calls logged</span>
                    </span>
                @endif
            </dd>
        </div>

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

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-credit-card" aria-hidden="true"></i> Payment</dt>
            <dd>
                @if($lastPayment)
                    <span class="c360-customer-snapshot-value">{{ $lastPayment['label'] }}</span>
                    <time class="c360-customer-snapshot-meta"
                          datetime="{{ $lastPayment['occurred_at']->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastPayment['occurred_at']) }}">
                        {{ AppDateFormatter::timelineRelative($lastPayment['occurred_at']) }}
                    </time>
                @else
                    <x-c360.unavailable-pill />
                @endif
            </dd>
        </div>

        <div class="c360-customer-snapshot-item">
            <dt><i class="bi bi-tools" aria-hidden="true"></i> Active Cases</dt>
            <dd class="c360-customer-snapshot-value">{{ $healthCard['active_service_cases'] ?? 0 }}</dd>
        </div>
    </dl>
</section>
