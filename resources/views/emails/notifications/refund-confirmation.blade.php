@extends('emails.layouts.master')

@section('title', 'Refund Confirmation')

@section('preheader', 'Your refund has been processed.')

@section('email_title', 'Refund Confirmation')

@section('greeting')
Dear {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        This email confirms that your refund request has been processed.
    </p>

    @if(filled($reference ?? null))
        <p style="margin: 0 0 16px;">
            <strong>Reference:</strong> {{ $reference }}
        </p>
    @endif

    @if(filled($refund_amount ?? null))
        <p style="margin: 0 0 16px;">
            <strong>Refund Amount:</strong> {{ $refund_amount }}
        </p>
    @endif

    <p style="margin: 0;">
        If you have any questions about this refund, reply to this email and our team will help.
    </p>
@endsection

@section('contact_email', 'support@radiumbox.com')

@section('signature')
Team Radium Box
@endsection
