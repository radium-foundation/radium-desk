@props([
    'healthCard' => [],
    'activeServices' => [],
    'summary' => [],
])

@php
    use App\Support\AppDateFormatter;

    $warrantyRaw = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? ($healthCard['warranty_status'] ?? null);
    $amcRaw = collect($activeServices)->firstWhere('label', 'AMC')['status'] ?? null;
    $lastPayment = $healthCard['last_payment'] ?? null;
    $lastWhatsapp = $healthCard['last_whatsapp'] ?? [];
    $lastEmail = $healthCard['last_email'] ?? [];
    $phone = trim((string) ($healthCard['phone'] ?? ''));
    $email = trim((string) ($healthCard['email'] ?? ''));
    $activeCases = (int) ($healthCard['active_service_cases'] ?? $summary['open_cases'] ?? 0);

    $mapServiceStatus = static function (?string $raw, string $type): array {
        if (! filled($raw) || $raw === 'Not Available') {
            return [
                'label' => $type === 'warranty' ? 'No Warranty Found' : 'No AMC',
                'variant' => 'neutral',
            ];
        }

        $lower = strtolower($raw);

        if (str_contains($lower, 'active')) {
            return ['label' => 'Active', 'variant' => 'success'];
        }

        if (str_contains($lower, 'expired')) {
            return ['label' => 'Expired', 'variant' => 'danger'];
        }

        if (str_contains($lower, 'not verified') || str_contains($lower, 'unverified')) {
            return ['label' => 'Not Verified', 'variant' => 'warning'];
        }

        if (str_contains($lower, 'verification pending') || (str_contains($lower, 'pending') && str_contains($lower, 'verif'))) {
            return ['label' => 'Verification Pending', 'variant' => 'warning'];
        }

        if ($type === 'amc' && (str_contains($lower, 'not enrolled') || str_contains($lower, 'no amc'))) {
            return ['label' => 'No AMC', 'variant' => 'neutral'];
        }

        if ($type === 'warranty' && str_contains($lower, 'no warranty')) {
            return ['label' => 'No Warranty Found', 'variant' => 'neutral'];
        }

        return ['label' => $raw, 'variant' => 'info'];
    };

    $mapCommStatus = static function (array $communication): array {
        $status = $communication['status'] ?? 'not_sent';

        return match ($status) {
            'sent' => ['label' => 'Sent', 'variant' => 'success'],
            'failed' => ['label' => 'Failed', 'variant' => 'danger'],
            default => ['label' => 'Never sent', 'variant' => 'neutral'],
        };
    };

    $warranty = $mapServiceStatus($warrantyRaw, 'warranty');
    $amc = $mapServiceStatus($amcRaw, 'amc');
    $whatsappComm = $mapCommStatus($lastWhatsapp);
    $emailComm = $mapCommStatus($lastEmail);

    $paymentAmount = null;
    $paymentMethod = null;

    if (is_array($lastPayment) && filled($lastPayment['label'] ?? null)) {
        $paymentParts = array_map('trim', explode('·', (string) $lastPayment['label'], 2));
        $paymentAmount = $paymentParts[0] ?? null;
        $paymentMethod = $paymentParts[1] ?? null;
    }
@endphp

<section {{ $attributes->merge(['class' => 'c360-customer-snapshot']) }}
         data-customer-360-section="health-card"
         aria-labelledby="c360-customer-snapshot-heading">
    <h2 class="c360-customer-snapshot-heading" id="c360-customer-snapshot-heading">Customer snapshot</h2>

    <div class="c360-snapshot-body">
        <div class="c360-snapshot-section">
            <h3 class="c360-snapshot-section-label">Contact</h3>
            <div class="c360-snapshot-contact-grid">
                <div class="c360-snapshot-contact-item">
                    <span class="c360-snapshot-field-label">
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        Phone
                    </span>
                    @if($phone !== '')
                        <div class="c360-snapshot-contact-value">
                            <a href="tel:{{ $phone }}"
                               class="c360-snapshot-contact-link"
                               title="Call customer">{{ $phone }}</a>
                            <button type="button"
                                    class="customer-360-inline-copy"
                                    data-customer-360-copy="phone"
                                    data-copy-value="{{ $phone }}"
                                    data-copy-label="Customer Phone"
                                    title="Copy phone"
                                    aria-label="Copy Customer Phone">
                                <i class="bi bi-clipboard" aria-hidden="true" data-customer-360-copy-icon></i>
                                <span class="customer-360-inline-copy-check" aria-hidden="true" hidden>✓</span>
                            </button>
                        </div>
                    @else
                        <span class="c360-snapshot-placeholder">Not provided</span>
                    @endif
                </div>

                <div class="c360-snapshot-contact-item">
                    <span class="c360-snapshot-field-label">
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        Email
                    </span>
                    @if($email !== '')
                        <div class="c360-snapshot-contact-value">
                            <a href="mailto:{{ $email }}"
                               class="c360-snapshot-contact-link"
                               title="Email customer">{{ $email }}</a>
                            <button type="button"
                                    class="customer-360-inline-copy"
                                    data-customer-360-copy="email"
                                    data-copy-value="{{ $email }}"
                                    data-copy-label="Customer Email"
                                    title="Copy email"
                                    aria-label="Copy Customer Email">
                                <i class="bi bi-clipboard" aria-hidden="true" data-customer-360-copy-icon></i>
                                <span class="customer-360-inline-copy-check" aria-hidden="true" hidden>✓</span>
                            </button>
                        </div>
                    @else
                        <span class="c360-snapshot-placeholder">Not provided</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="c360-snapshot-section">
            <h3 class="c360-snapshot-section-label">Recent communication</h3>
            <div class="c360-snapshot-comm-list">
                <button type="button"
                        class="c360-snapshot-comm-row"
                        data-c360-snapshot-action="open-timeline"
                        data-timeline-filter="notifications"
                        aria-label="View WhatsApp communication history">
                    <span class="c360-snapshot-comm-main">
                        <span class="c360-snapshot-comm-channel">
                            <i class="bi bi-whatsapp c360-snapshot-comm-icon c360-snapshot-comm-icon--whatsapp" aria-hidden="true"></i>
                            WhatsApp
                        </span>
                        <span class="c360-snapshot-status-chip c360-snapshot-status-chip--{{ $whatsappComm['variant'] }}">
                            {{ $whatsappComm['label'] }}
                        </span>
                        @if(($lastWhatsapp['status'] ?? 'not_sent') !== 'not_sent' && filled($lastWhatsapp['last_sent_label'] ?? null))
                            <time class="c360-snapshot-comm-time"
                                  datetime="{{ $lastWhatsapp['last_sent_at']?->toIso8601String() }}"
                                  title="{{ AppDateFormatter::timelineDatetime($lastWhatsapp['last_sent_at']) }}">
                                {{ $lastWhatsapp['last_sent_label'] }}
                            </time>
                        @endif
                    </span>
                    <span class="c360-snapshot-comm-link">View history <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </button>

                <button type="button"
                        class="c360-snapshot-comm-row"
                        data-c360-snapshot-action="open-timeline"
                        data-timeline-filter="notifications"
                        aria-label="View email communication history">
                    <span class="c360-snapshot-comm-main">
                        <span class="c360-snapshot-comm-channel">
                            <i class="bi bi-envelope c360-snapshot-comm-icon" aria-hidden="true"></i>
                            Email
                        </span>
                        @if(($lastEmail['status'] ?? 'not_sent') !== 'not_sent')
                            <span class="c360-snapshot-status-chip c360-snapshot-status-chip--{{ $emailComm['variant'] }}">
                                {{ $emailComm['label'] }}
                            </span>
                            @if(filled($lastEmail['last_sent_label'] ?? null))
                                <time class="c360-snapshot-comm-time"
                                      datetime="{{ $lastEmail['last_sent_at']?->toIso8601String() }}"
                                      title="{{ AppDateFormatter::timelineDatetime($lastEmail['last_sent_at']) }}">
                                    {{ $lastEmail['last_sent_label'] }}
                                </time>
                            @endif
                        @else
                            <span class="c360-snapshot-status-chip c360-snapshot-status-chip--neutral">Never sent</span>
                        @endif
                    </span>
                    <span class="c360-snapshot-comm-link">View history <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </button>
            </div>
        </div>

        <div class="c360-snapshot-section c360-snapshot-section--service">
            <h3 class="c360-snapshot-section-label">Service status</h3>
            <div class="c360-snapshot-service-grid">
                @if(is_array($lastPayment) && ($lastPayment['occurred_at'] ?? null) !== null)
                    <div class="c360-snapshot-service-item">
                        <span class="c360-snapshot-field-label">
                            <i class="bi bi-credit-card" aria-hidden="true"></i>
                            Payment
                        </span>
                        <span class="c360-snapshot-service-value">
                            @if(filled($paymentAmount))
                                <span class="c360-snapshot-payment-amount">{{ $paymentAmount }}</span>
                            @endif
                            @if(filled($paymentMethod))
                                <span class="c360-snapshot-payment-method">{{ $paymentMethod }}</span>
                            @endif
                        </span>
                        <time class="c360-snapshot-meta"
                              datetime="{{ $lastPayment['occurred_at']->toIso8601String() }}"
                              title="{{ AppDateFormatter::timelineDatetime($lastPayment['occurred_at']) }}">
                            {{ AppDateFormatter::timelineRelative($lastPayment['occurred_at']) }}
                        </time>
                    </div>
                @endif

                <div class="c360-snapshot-service-item">
                    <span class="c360-snapshot-field-label">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        Warranty
                    </span>
                    <span class="c360-snapshot-status-chip c360-snapshot-status-chip--{{ $warranty['variant'] }}">
                        {{ $warranty['label'] }}
                    </span>
                </div>

                <div class="c360-snapshot-service-item">
                    <span class="c360-snapshot-field-label">
                        <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
                        AMC
                    </span>
                    <span class="c360-snapshot-status-chip c360-snapshot-status-chip--{{ $amc['variant'] }}">
                        {{ $amc['label'] }}
                    </span>
                </div>

                <div class="c360-snapshot-service-item">
                    <span class="c360-snapshot-field-label">
                        <i class="bi bi-tools" aria-hidden="true"></i>
                        Active cases
                    </span>
                    @if($activeCases > 0)
                        <button type="button"
                                class="c360-snapshot-active-cases"
                                data-c360-snapshot-action="open-timeline"
                                data-timeline-filter="support"
                                aria-label="View {{ $activeCases }} active {{ str('case')->plural($activeCases) }}">
                            <span class="c360-snapshot-active-cases-count">{{ $activeCases }}</span>
                            <span class="c360-snapshot-active-cases-label">Active {{ str('Case')->plural($activeCases) }}</span>
                            <i class="bi bi-arrow-right c360-snapshot-active-cases-arrow" aria-hidden="true"></i>
                        </button>
                    @else
                        <span class="c360-snapshot-placeholder">No active cases</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
