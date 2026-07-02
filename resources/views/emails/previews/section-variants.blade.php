@extends('emails.layouts.master')

@section('title', 'Email Section Variants Preview')

@section('email_title', 'Notification Design System')

@section('greeting')
Dear Customer,
@endsection

@section('content')
    <p style="margin: 0 0 24px;">
        The master layout supports optional title, greeting, content, information box, CTA button, contact block, and footer sections.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 16px;">
        <tr>
            <td style="padding: 16px 18px; background-color: #e7f1ff; border: 1px solid #b6d4fe; border-left: 4px solid #0d6efd; border-radius: 6px;">
                <div style="font-size: 14px; line-height: 1.6; color: #084298;">
                    <strong>Info</strong> — General guidance or next steps.
                </div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 16px;">
        <tr>
            <td style="padding: 16px 18px; background-color: #d1e7dd; border: 1px solid #badbcc; border-left: 4px solid #198754; border-radius: 6px;">
                <div style="font-size: 14px; line-height: 1.6; color: #0f5132;">
                    <strong>Success</strong> — Confirmation or completed action messaging.
                </div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 16px;">
        <tr>
            <td style="padding: 16px 18px; background-color: #fff3cd; border: 1px solid #ffecb5; border-left: 4px solid #997404; border-radius: 6px;">
                <div style="font-size: 14px; line-height: 1.6; color: #664d03;">
                    <strong>Warning</strong> — Time-sensitive follow-up or pending action.
                </div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 24px;">
        <tr>
            <td style="padding: 16px 18px; background-color: #f8d7da; border: 1px solid #f5c2c7; border-left: 4px solid #dc3545; border-radius: 6px;">
                <div style="font-size: 14px; line-height: 1.6; color: #842029;">
                    <strong>Error</strong> — Failed validation or blocked action messaging.
                </div>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px;">
        <tr>
            <td align="left" style="border-radius: 6px; background-color: #0d6efd;">
                <span style="display: inline-block; padding: 12px 20px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 600; line-height: 1.2; color: #ffffff; border-radius: 6px;">
                    Example CTA Button
                </span>
            </td>
        </tr>
    </table>
@endsection

@section('contact_email', 'support@radiumbox.com')

@section('contact_phone', '+91 XXXXX XXXXX')

@section('signature')
Team Radium Box
@endsection
