@extends('emails.layouts.master')

@section('title', 'Schedule Your Callback')

@section('preheader', 'We could not connect with you. Schedule a convenient callback time.')

@section('email_title', 'We Tried Reaching You')

@section('greeting')
Hi {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        We tried contacting you regarding your support request {{ $reference }}, but could not connect.
    </p>

    <p style="margin: 0 0 16px;">
        Please schedule a convenient time for us to call you back between 9:00 AM and 6:00 PM.
    </p>

    <p style="margin: 0;">
        Need assistance sooner? Reply to this email and our team will help.
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Schedule Callback')

@section('signature')
    <span style="display: block; margin: 0 0 16px;">
        We will continue your support once we connect with you.
    </span>

    Thank you,<br>
    Radium Support Desk
@endsection
