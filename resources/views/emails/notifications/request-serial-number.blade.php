@extends('emails.layouts.master')

@section('title', 'Help Us Complete Your Device Setup')

@section('preheader', 'Reply with your device serial number or let us know a convenient time to call.')

@section('email_title', 'Help Us Complete Your Device Setup')

@section('greeting')
Dear {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        We are reaching out to provide dedicated technical support and get your biometric device set up successfully.
    </p>

    <p style="margin: 0 0 16px;">
        To ensure a smooth setup, please let us know a convenient time for us to call you between 9:00 AM and 6:00 PM.
    </p>

    <p style="margin: 0 0 12px;">
        Alternatively, to fast-track your service, simply reply to this email with:
    </p>

    <ul style="margin: 0 0 16px; padding-left: 20px;">
        <li style="margin-bottom: 8px;">A clear photo of the back of your device showing the serial number, or</li>
        <li style="margin-bottom: 0;">A screenshot of the device's internal serial number.</li>
    </ul>

    <p style="margin: 0 0 16px;">
        Once we receive the serial number, we'll proceed with your request right away.
    </p>

    <p style="margin: 0;">
        Looking forward to getting you successfully connected!
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Schedule Technical Support')

@section('signature')
Team Radium Box
@endsection
