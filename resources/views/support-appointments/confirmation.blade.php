@extends('layouts.customer')

@php
    use App\Support\AppDateFormatter;
@endphp

@section('title', 'Appointment Confirmed')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4 text-center">
            <div class="support-appointment-confirmation-icon mb-3" aria-hidden="true">✓</div>

            <h2 class="h5 fw-semibold mb-2">Your appointment is confirmed</h2>
            <p class="text-muted small mb-4">
                Thank you! Our technical support team will call you at the scheduled time.
            </p>

            <dl class="support-appointment-summary text-start mb-0">
                <div class="support-appointment-summary-item">
                    <dt>Preferred date</dt>
                    <dd>{{ AppDateFormatter::date($appointment->preferred_date) }}</dd>
                </div>
                <div class="support-appointment-summary-item">
                    <dt>Time slot</dt>
                    <dd>{{ $appointment->preferred_time_slot->label() }}</dd>
                </div>
                <div class="support-appointment-summary-item">
                    <dt>Phone number</dt>
                    <dd>{{ $appointment->phone_number }}</dd>
                </div>
                @if (filled($appointment->additional_notes))
                    <div class="support-appointment-summary-item">
                        <dt>Notes</dt>
                        <dd>{{ $appointment->additional_notes }}</dd>
                    </div>
                @endif
                @if ($incident->display_reference)
                    <div class="support-appointment-summary-item">
                        <dt>Service case</dt>
                        <dd>{{ $incident->display_reference }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>
@endsection
