@extends('emails.layouts.master')

@section('title', 'Share Your Feedback')

@section('preheader', 'Tell us about your recent remote support experience.')

@section('email_title', 'How Did We Do?')

@section('greeting')
Dear {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        We hope your recent remote support session went smoothly. Your feedback helps us improve the support we provide.
    </p>

    <p style="margin: 0;">
        Please take a moment to share your experience with us.
    </p>
@endsection

@section('cta_url', $review_url ?? '')

@section('cta_label', 'Leave a Review')

@section('contact_email', 'support@radiumbox.com')

@section('signature')
Team Radium Box
@endsection
