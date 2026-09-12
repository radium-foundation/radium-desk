@extends('emails.layouts.master')

@section('title', 'Tax invoice '.$invoice_number)

@section('preheader', 'Your tax invoice '.$invoice_number.' is attached.')

@section('email_title', 'Tax invoice '.$invoice_number)

@section('greeting')
Hello {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Please find tax invoice <strong>{{ $invoice_number }}</strong> attached as a PDF.
    </p>
    <p style="margin: 0;">
        If you have a question about this invoice, reply to this email and our team will help.
    </p>
@endsection

@section('cta_url', '')

@section('signature')
    Kind regards,<br><br>
    Team {{ $company_name ?? app(\App\Services\BrandingService::class)->companyName() }}
@endsection
