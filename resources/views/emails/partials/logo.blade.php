@php($branding = app(\App\Services\BrandingService::class))
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="width: 32px; height: 32px; text-align: center; vertical-align: middle;">
            @if($branding->hasIcon())
                <img src="{{ $branding->iconUrl() }}"
                     alt="{{ $branding->companyName() }}"
                     width="32"
                     height="32"
                     style="display: block; width: 32px; height: 32px; object-fit: contain;">
            @elseif($branding->hasLogo())
                <img src="{{ $branding->logoUrl() }}"
                     alt="{{ $branding->companyName() }}"
                     width="32"
                     height="32"
                     style="display: block; width: 32px; height: 32px; object-fit: contain;">
            @else
                <span style="display: inline-block; width: 32px; height: 32px; background-color: #0d6efd; border-radius: 6px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; line-height: 32px; color: #ffffff; letter-spacing: -0.03em;">RB</span>
            @endif
        </td>
        <td style="padding-left: 10px; vertical-align: middle;">
            <span style="font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: 600; line-height: 1.2; color: #212529; letter-spacing: -0.02em;">{{ $branding->companyName() }}</span>
        </td>
    </tr>
</table>
