@extends('emails.layouts.master')

@section('title', 'Support Reminder')

@section('preheader', 'We are waiting for the details requested earlier to continue your support.')

@section('email_title', 'Support Reminder')

@section('greeting')
Hi {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        This is a reminder regarding your support request {{ $reference }}.
    </p>

    <p style="margin: 0 0 16px;">
        We are waiting for the details requested earlier to continue your support.
    </p>

    <p style="margin: 0;">
        Need assistance?
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Book Support')

@section('signature')
    <span style="display: block; margin: 0 0 16px;">
        This request is paused until we receive your details.<br>
        You can continue anytime by sharing the required information.
    </span>

    Thank you,<br>
    Radium Support Desk
@endsection
