@extends('emails.layouts.master')

@section('title', 'Reminder: We Still Need Your Information')

@section('preheader', 'We are still waiting for the information needed to continue your service request.')

@section('email_title', 'Reminder: We Still Need Your Information')

@section('greeting')
Dear {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        This is a friendly reminder that we are still waiting for the information needed to continue your service request.
    </p>

    <p style="margin: 0 0 16px;">
        Please reply with the requested details, upload the required photo, or schedule a convenient time for us to call you between 9:00 AM and 6:00 PM.
    </p>

    <p style="margin: 0;">
        If we do not hear back within 24 hours, we may close this request automatically while preserving your full service history.
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Schedule Technical Support')

@section('contact_email', 'support@radiumbox.com')

@section('contact_phone', '+91 XXXXX XXXXX')

@section('signature')
Team Radium Box
@endsection
