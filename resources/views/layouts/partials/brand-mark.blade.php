@php($branding = app(\App\Services\BrandingService::class))
@if($branding->hasIcon())
    <img src="{{ $branding->iconUrl() }}" alt="{{ $branding->companyName() }}" class="brand-logo">
@elseif($branding->hasLogo())
    <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->companyName() }}" class="brand-logo">
@else
    <i class="bi bi-headset text-primary nav-icon fs-5" aria-hidden="true"></i>
@endif
<span class="brand-text text-white fw-semibold ms-2">{{ $branding->appName() }}</span>
