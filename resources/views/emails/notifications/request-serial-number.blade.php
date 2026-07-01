@extends('emails.notifications.layout')

@section('title', 'Please provide your device serial number')

@section('content')
    <p>Dear {{ $customer_name }},</p>

    <p>To continue processing your service request, we require your device serial number.</p>

    <p>You can reply to this email with:</p>

    <ul>
        <li>A clear photo of the serial-number label<br>OR</li>
        <li>A screenshot/photo showing the serial number</li>
    </ul>

    <p>Once received, our support team will continue your repair immediately.</p>

    <p>
        Thank you,<br>
        Radium Support
    </p>
@endsection
