@php($branding = app(\App\Services\BrandingService::class))
@if($branding->hasLogo())
    <img src="{{ $branding->logoUrl() }}"
         alt="{{ $branding->companyName() }}"
         width="180"
         height="67"
         style="display: block; width: 180px; max-width: 100%; height: auto; border: 0;">
@elseif($branding->hasIcon())
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="width: 32px; height: 32px; text-align: center; vertical-align: middle;">
                <img src="{{ $branding->iconUrl() }}"
                     alt="{{ $branding->companyName() }}"
                     width="32"
                     height="32"
                     style="display: block; width: 32px; height: 32px; object-fit: contain; border: 0;">
            </td>
            <td style="padding-left: 10px; vertical-align: middle;">
                <span style="font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: 600; line-height: 1.2; color: #212529; letter-spacing: -0.02em;">{{ $branding->companyName() }}</span>
            </td>
        </tr>
    </table>
@else
    <span style="display: inline-block; font-family: Arial, Helvetica, sans-serif; font-size: 18px; font-weight: 600; line-height: 1.2; color: #212529; letter-spacing: -0.02em;">{{ $branding->companyName() }}</span>
@endif
