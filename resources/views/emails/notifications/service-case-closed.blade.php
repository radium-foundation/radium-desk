@extends('emails.layouts.master')

@section('title', 'Your Service Case Is Complete')

@section('preheader', 'Your service case has been closed.')

@section('email_title', 'Your Service Case Is Complete')

@section('greeting')
Hi {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Repair work for case {{ $reference }} is complete.
    </p>

    <p style="margin: 0 0 16px;">
        Please let us know if you need any additional assistance.
    </p>

    <p style="margin: 0;">
        Thank you,<br>
        Radium Desk Support
    </p>
@endsection

@section('signature')
Team Radium Box
@endsection
