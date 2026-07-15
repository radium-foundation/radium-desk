@extends('emails.layouts.master')

@section('title', 'Your Refund Has Been Processed')

@section('preheader', 'Your refund has been successfully processed.')

@section('email_title', 'Your Refund Has Been Processed')

@section('greeting')
Hello {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Your refund has been successfully processed.
    </p>

    @if(filled($refund_amount ?? null))
        <p style="margin: 0 0 8px;">
            <strong>Refund Amount</strong>
        </p>
        <p style="margin: 0 0 16px;">
            {{ $refund_amount }}
        </p>
    @endif

    @if(filled($refund_reference ?? null))
        <p style="margin: 0 0 8px;">
            <strong>Reference Number</strong>
        </p>
        <p style="margin: 0 0 16px;">
            {{ $refund_reference }}
        </p>
    @endif

    <p style="margin: 0;">
        If you have any questions, simply reply to this email.
    </p>
@endsection

@section('signature')
    Kind regards,<br><br>
    Team {{ $company_name }}
@endsection
