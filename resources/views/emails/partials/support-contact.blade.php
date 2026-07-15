@php
    $resolvedEmail = trim($support_email ?? '');
    $resolvedPhone = trim($support_phone ?? '');
    $resolvedWhatsapp = trim($support_whatsapp ?? '');
    $resolvedWebsite = trim($support_website ?? '');
    $resolver = app(\App\Services\SupportContactResolver::class);
    $phoneTelHref = $resolver->phoneTelHref($resolvedPhone);
    $whatsappHref = $resolver->whatsappHref($resolvedWhatsapp);
@endphp

@if ($resolvedEmail !== '' || $resolvedPhone !== '' || $resolvedWhatsapp !== '' || $resolvedWebsite !== '')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0 0; border-top: 1px solid #e9ecef; border-bottom: 1px solid #e9ecef;">
        <tr>
            <td style="padding: 20px 0;">
                <p style="margin: 0 0 12px; font-size: 14px; font-weight: 600; color: #495057;">
                    Need Help?
                </p>

                @if ($resolvedEmail !== '')
                    <p style="margin: 0 0 8px; font-size: 14px; color: #212529;">
                        <strong style="color: #495057;">Email:</strong>
                        <a href="mailto:{{ $resolvedEmail }}" style="color: #0d6efd; text-decoration: none;">{{ $resolvedEmail }}</a>
                    </p>
                @endif

                @if ($resolvedPhone !== '')
                    <p style="margin: 0 0 8px; font-size: 14px; color: #212529;">
                        <strong style="color: #495057;">Phone:</strong>
                        <a href="{{ $phoneTelHref }}" style="color: #0d6efd; text-decoration: none;">{{ $resolvedPhone }}</a>
                    </p>
                @endif

                @if ($resolvedWhatsapp !== '' && $whatsappHref !== '')
                    <p style="margin: 0 0 8px; font-size: 14px; color: #212529;">
                        <strong style="color: #495057;">WhatsApp:</strong>
                        <a href="{{ $whatsappHref }}" target="_blank" rel="noopener noreferrer" style="color: #0d6efd; text-decoration: none;">{{ $resolvedWhatsapp }}</a>
                    </p>
                @endif

                @if ($resolvedWebsite !== '')
                    <p style="margin: 0; font-size: 14px; color: #212529;">
                        <strong style="color: #495057;">Website:</strong>
                        <a href="{{ $resolvedWebsite }}" target="_blank" rel="noopener noreferrer" style="color: #0d6efd; text-decoration: none;">{{ $resolvedWebsite }}</a>
                    </p>
                @endif
            </td>
        </tr>
    </table>
@endif
