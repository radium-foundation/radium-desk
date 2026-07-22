@extends('emails.layouts.master')

@section('title', 'Update Regarding Your Support Ticket')

@section('preheader', 'We will change your ticket status to resolved shortly unless you contact us.')

@section('email_title', 'Update Regarding Your Support Ticket')

@section('greeting')
Hi {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        We are following up on your recent support request ({{ $reference }}). Since we haven't received an update, we will change the ticket status to resolved shortly.
    </p>

    <p style="margin: 0;">
        If you still require assistance, simply click the <strong>Contact Support</strong> button below and we'll be happy to help.
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Contact Support')

@section('signature')
    Best regards,<br>
    {{ $company_name }} Support Team
@endsection
