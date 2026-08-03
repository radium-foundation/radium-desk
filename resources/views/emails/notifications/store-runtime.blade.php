@extends('emails.layouts.master')

@section('title', $mail_subject ?? 'Notification')

@section('content')
{!! $store_body_html !!}
@endsection
