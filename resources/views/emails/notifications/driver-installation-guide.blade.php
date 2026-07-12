@extends('emails.layouts.master')

@section('title', 'Driver Installation Guide')

@section('preheader', 'Follow these steps to install your biometric device driver.')

@section('email_title', 'Driver Installation Guide')

@section('greeting')
Dear {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Thank you for choosing Radium Box. Use the instructions below to install the driver for your biometric device.
    </p>

    @if(filled($reference_number ?? null))
        <p style="margin: 0 0 16px;">
            <strong>Reference:</strong> {{ $reference_number }}
        </p>
    @endif

    @if(filled($order_id ?? null))
        <p style="margin: 0 0 16px;">
            <strong>Order ID:</strong> {{ $order_id }}
        </p>
    @endif

    <p style="margin: 0;">
        If you need help during installation, reply to this email and our remote support team will assist you.
    </p>
@endsection

@section('contact_email', 'support@radiumbox.com')

@section('signature')
Team Radium Box
@endsection
