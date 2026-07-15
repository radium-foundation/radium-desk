@extends('emails.layouts.master')

@section('title', 'How Was Your Experience with Radium?')

@section('preheader', "We'd love your feedback.")

@section('email_title', 'How Was Your Experience with Radium?')

@section('greeting')
Hello {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Thank you for choosing {{ $company_name }}.
    </p>

    <p style="margin: 0 0 16px;">
        We hope your recent support experience went well. Your feedback helps us improve the service we provide to customers like you.
    </p>

    <p style="margin: 0;">
        Please take a moment to share your experience on Google.
    </p>
@endsection

@section('cta_url', $review_url ?? '')

@section('cta_label', 'Leave a Review')

@section('signature')
    Kind regards,<br><br>
    Team {{ $company_name }}
@endsection
