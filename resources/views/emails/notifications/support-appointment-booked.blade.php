@extends('emails.layouts.master')

@section('title', 'Your Support Appointment Is Confirmed')

@section('preheader', 'Your support has been booked successfully.')

@section('email_title', 'Your Support Appointment Is Confirmed')

@section('greeting')
Hi {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Your support has been booked successfully.
    </p>

    <p style="margin: 0 0 8px;">
        <strong>Order:</strong> {{ $order_id }}
    </p>

    <p style="margin: 0 0 8px;">
        <strong>Preferred Date:</strong><br>
        {{ $preferred_date }}
    </p>

    <p style="margin: 0 0 16px;">
        <strong>Preferred Time:</strong><br>
        {{ $preferred_time_slot }}
    </p>

    <p style="margin: 0 0 16px;">
        Our technical team will contact you during your selected time.
    </p>

    <p style="margin: 0;">
        Thank you,<br>
        Radium Desk Support
    </p>
@endsection

@section('contact_email', 'support@radiumbox.com')

@section('contact_phone', '+91 XXXXX XXXXX')

@section('signature')
Team Radium Box
@endsection
