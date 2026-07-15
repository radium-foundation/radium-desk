@extends('emails.layouts.master')

@section('title', 'Protect Your Device with RD Service')

@section('preheader', 'Learn more about RD Service for your device.')

@section('email_title', 'Protect Your Device with RD Service')

@section('greeting')
Hello {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Thank you for choosing {{ $company_name }}.
    </p>

    <p style="margin: 0;">
        RD Service helps keep your biometric device running smoothly with ongoing support and updates. Learn more about RD Service for your device.
    </p>
@endsection

@section('cta_url', $buy_rd_service_url ?? '')

@section('cta_label', 'Get RD Service')

@section('signature')
    Kind regards,<br><br>
    Team {{ $company_name }}
@endsection
