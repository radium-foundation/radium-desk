@php
    $accentColor = '#0d6efd';

    $statusVariants = [
        'info' => [
            'accent' => '#0d6efd',
            'background' => '#e7f1ff',
            'border' => '#b6d4fe',
            'text' => '#084298',
        ],
        'success' => [
            'accent' => '#198754',
            'background' => '#d1e7dd',
            'border' => '#badbcc',
            'text' => '#0f5132',
        ],
        'warning' => [
            'accent' => '#997404',
            'background' => '#fff3cd',
            'border' => '#ffecb5',
            'text' => '#664d03',
        ],
        'error' => [
            'accent' => '#dc3545',
            'background' => '#f8d7da',
            'border' => '#f5c2c7',
            'text' => '#842029',
        ],
    ];

    $infoBoxVariant = trim($__env->yieldContent('info_box_variant', 'info'));
    $infoBoxColors = $statusVariants[$infoBoxVariant] ?? $statusVariants['info'];
    $branding = app(\App\Services\BrandingService::class);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', $branding->companyName())</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f8f9fa; font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #212529; -webkit-text-size-adjust: 100%;">
    @hasSection('preheader')
        <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent; mso-hide: all;">
            @yield('preheader')
        </div>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f8f9fa;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 8px;">
                    <tr>
                        <td style="padding: 24px 32px; border-bottom: 1px solid #e9ecef;">
                            @include('emails.partials.logo')
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 32px;">
                            @hasSection('email_title')
                                <h1 style="margin: 0 0 24px; font-size: 22px; font-weight: 600; line-height: 1.3; color: #212529;">
                                    @yield('email_title')
                                </h1>
                            @endif

                            @hasSection('greeting')
                                <p style="margin: 0 0 16px; font-size: 15px; color: #212529;">
                                    @yield('greeting')
                                </p>
                            @endif

                            @hasSection('content')
                                <div style="font-size: 15px; color: #212529;">
                                    @yield('content')
                                </div>
                            @endif

                            @hasSection('info_box')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0 0;">
                                    <tr>
                                        <td style="padding: 16px 18px; background-color: {{ $infoBoxColors['background'] }}; border: 1px solid {{ $infoBoxColors['border'] }}; border-left: 4px solid {{ $infoBoxColors['accent'] }}; border-radius: 6px;">
                                            <div style="font-size: 14px; line-height: 1.6; color: {{ $infoBoxColors['text'] }};">
                                                @yield('info_box')
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if (trim($__env->yieldContent('cta_url')) !== '' && trim($__env->yieldContent('cta_label')) !== '')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0 0;">
                                    <tr>
                                        <td align="left" style="border-radius: 6px; background-color: {{ $accentColor }};">
                                            <a href="@yield('cta_url')" target="_blank" style="display: inline-block; padding: 12px 20px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 600; line-height: 1.2; color: #ffffff; text-decoration: none; border-radius: 6px;">
                                                @yield('cta_label')
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @hasSection('contact')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0 0; border-top: 1px solid #e9ecef; border-bottom: 1px solid #e9ecef;">
                                    <tr>
                                        <td style="padding: 20px 0;">
                                            <div style="font-size: 14px; line-height: 1.6; color: #212529;">
                                                @yield('contact')
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            @elseif (trim($__env->yieldContent('contact_email')) !== '')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0 0; border-top: 1px solid #e9ecef; border-bottom: 1px solid #e9ecef;">
                                    <tr>
                                        <td style="padding: 20px 0;">
                                            @if (trim($__env->yieldContent('contact_heading')) !== '')
                                                <p style="margin: 0 0 12px; font-size: 14px; font-weight: 600; color: #495057;">
                                                    @yield('contact_heading')
                                                </p>
                                            @endif

                                            <p style="margin: 0 0 8px; font-size: 14px; color: #212529;">
                                                <strong style="color: #495057;">Email:</strong>
                                                <a href="mailto:@yield('contact_email')" style="color: {{ $accentColor }}; text-decoration: none;">@yield('contact_email')</a>
                                            </p>

                                            @if (trim($__env->yieldContent('contact_phone')) !== '')
                                                <p style="margin: 0; font-size: 14px; color: #212529;">
                                                    <strong style="color: #495057;">Phone:</strong>
                                                    @yield('contact_phone')
                                                </p>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @hasSection('signature')
                                <p style="margin: 24px 0 0; font-size: 15px; color: #212529;">
                                    @yield('signature')
                                </p>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 20px 32px 32px; border-top: 1px solid #e9ecef;">
                            @hasSection('footer')
                                <div style="font-size: 13px; line-height: 1.5; color: #6c757d; text-align: center;">
                                    @yield('footer')
                                </div>
                            @else
                                <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #6c757d; text-align: center;">
                                    &copy; {{ date('Y') }} {{ $branding->companyName() }}. All rights reserved.
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
