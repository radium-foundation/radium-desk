@extends('emails.layouts.master')

@section('title', 'Final Reminder Before Closure')

@section('preheader', 'Final reminder before we close your support request.')

@section('email_title', 'Final Reminder Before Closure')

@section('greeting')
Hi {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        We have been unable to reach you regarding your support request {{ $reference }}.
    </p>

    <p style="margin: 0 0 16px;">
        This is a final reminder before we close your case. Please contact us if you still need assistance.
    </p>

    <p style="margin: 0;">
        If we do not hear back, we will proceed with closing this support request.
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Contact Support')

@section('signature')
    <span style="display: block; margin: 0 0 16px;">
        We are here to help if you still need support.
    </span>

    Thank you,<br>
    Radium Support Desk
@endsection
