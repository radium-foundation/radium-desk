@php
    use App\Support\AppDateFormatter;

    $showAppointmentPhone = ! filled($profilePhone ?? null);
@endphp

@if ($supportAppointments->isNotEmpty())
    <section class="customer-360-support-appointments"
             data-customer-360-section="support-appointments"
             aria-labelledby="customer-360-support-appointments-heading">
        <div class="customer-360-support-appointments-header">
            <span class="customer-360-support-appointments-indicator" aria-hidden="true">📅</span>
            <h2 class="customer-360-support-appointments-title" id="customer-360-support-appointments-heading">
                Scheduled Support
            </h2>
        </div>

        <div class="customer-360-support-appointments-list">
            @foreach ($supportAppointments as $appointment)
                <article class="customer-360-support-appointment-item">
                    <dl class="customer-360-support-appointment-grid">
                        <div class="customer-360-support-appointment-field">
                            <dt>Date</dt>
                            <dd>
                                <time datetime="{{ $appointment->preferred_date->toDateString() }}"
                                      title="{{ AppDateFormatter::date($appointment->preferred_date) }}">
                                    {{ AppDateFormatter::date($appointment->preferred_date) }}
                                </time>
                            </dd>
                        </div>
                        <div class="customer-360-support-appointment-field">
                            <dt>Time slot</dt>
                            <dd>{{ $appointment->preferred_time_slot->label() }}</dd>
                        </div>
                        @if (filled($incident->assignee?->firstName()))
                            <div class="customer-360-support-appointment-field">
                                <dt>Assigned To</dt>
                                <dd>{{ $incident->assignee->firstName() }}</dd>
                            </div>
                        @endif
                        @if ($showAppointmentPhone)
                            <div class="customer-360-support-appointment-field">
                                <dt>Phone</dt>
                                <dd>{{ $appointment->phone_number }}</dd>
                            </div>
                        @endif
                        @if (filled($appointment->additional_notes))
                            <div class="customer-360-support-appointment-field customer-360-support-appointment-field--full">
                                <dt>Notes</dt>
                                <dd>{{ $appointment->additional_notes }}</dd>
                            </div>
                        @endif
                        <div class="customer-360-support-appointment-field">
                            <dt>Booked</dt>
                            <dd>
                                <time datetime="{{ $appointment->created_at->toIso8601String() }}"
                                      title="{{ AppDateFormatter::datetime($appointment->created_at) }}">
                                    {{ AppDateFormatter::datetime($appointment->created_at) }}
                                </time>
                            </dd>
                        </div>
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
@endif
