@extends('emails.layouts.master')

@section('title', 'Driver Installation Guide for Your Device')

@section('preheader', 'Download the latest driver and complete the installation in just a few simple steps.')

@section('email_title', 'Driver Installation Guide')

@section('greeting')
Hello {{ $customer_name }},
@endsection

@section('content')
    <p style="margin: 0 0 16px;">
        Thank you for choosing {{ $company_name }}.
    </p>

    <p style="margin: 0 0 24px;">
        To ensure your device works correctly, please download and install the latest driver using the button below.
    </p>

    @if(filled($driver_download_link ?? null))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 32px;">
            <tr>
                <td align="left" style="border-radius: 6px; background-color: #0d6efd;">
                    <a href="{{ $driver_download_link }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 14px 24px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 600; line-height: 1.2; color: #ffffff; text-decoration: none; border-radius: 6px;">
                        Download Driver
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <h2 style="margin: 0 0 16px; font-size: 17px; font-weight: 600; line-height: 1.4; color: #212529;">
        After Installation
    </h2>

    <ol style="margin: 0 0 32px; padding-left: 20px; font-size: 15px; color: #212529;">
        <li style="margin-bottom: 10px;">Install the downloaded driver.</li>
        <li style="margin-bottom: 10px;">Restart your computer.</li>
        <li style="margin-bottom: 10px;">Reconnect your device.</li>
        <li style="margin-bottom: 0;">You are now ready to use your device.</li>
    </ol>

    <p style="margin: 0 0 16px;">
        If you continue to experience any issues after following these steps, our support team will be happy to assist you.
    </p>

    @if(filled($support_booking_link ?? null))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px;">
            <tr>
                <td align="left" style="border-radius: 6px; border: 1px solid #0d6efd;">
                    <a href="{{ $support_booking_link }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 12px 20px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 600; line-height: 1.2; color: #0d6efd; text-decoration: none; border-radius: 6px;">
                        Book a Support Session
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin: 0;">
        Or simply reply to this email, and we&rsquo;ll be happy to help.
    </p>
@endsection

@section('signature')
    Kind regards,<br><br>
    Team {{ $company_name }}<br>
    {{ $support_contact }}
@endsection
