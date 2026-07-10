@extends('emails.layouts.master')

@section('title', 'Please Confirm Your Device Serial Number')

@section('preheader', 'The serial number on file may be incorrect. Please reply with the correct serial from your device.')

@section('email_title', 'Please Confirm Your Device Serial Number')

@section('greeting')
Dear {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        We are reviewing your service request for order <strong>{{ $order_id }}</strong> and the serial number on file does not appear to match your device.
    </p>

    <p style="margin: 0 0 16px;">
        Could you please confirm the correct serial number? You can reply to this email with:
    </p>

    <ul style="margin: 0 0 16px; padding-left: 20px;">
        <li style="margin-bottom: 8px;">The serial number printed on the back of your device, or</li>
        <li style="margin-bottom: 0;">A clear photo of the device label showing the serial number.</li>
    </ul>

    <p style="margin: 0 0 16px;">
        Once we receive the correct serial, we will continue processing your service request without delay.
    </p>

    <p style="margin: 0;">
        Thank you for your help.
    </p>
@endsection

@section('cta_url', $booking_url ?? '')

@section('cta_label', 'Schedule Technical Support')

@section('contact_email', 'support@radiumbox.com')

@section('contact_phone', '+91 XXXXX XXXXX')

@section('signature')
Team Radium Box
@endsection
