@extends('emails.layouts.master')

@section('title', 'Recommended Product for Your Device')

@section('preheader', 'View the recommended product for your device.')

@section('email_title', 'Recommended Product for Your Device')

@section('greeting')
Hello {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Thank you for choosing {{ $company_name }}.
    </p>

    <p style="margin: 0;">
        Based on your device, we have a recommended product that may suit your needs. View the details using the link below.
    </p>
@endsection

@section('cta_url', $buy_device_url ?? '')

@section('cta_label', 'View Product')

@section('signature')
    Kind regards,<br><br>
    Team {{ $company_name }}
@endsection
