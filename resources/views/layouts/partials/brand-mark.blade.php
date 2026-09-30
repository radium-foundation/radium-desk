@php($branding = app(\App\Services\BrandingService::class))
@if($branding->hasLogo())
    <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->companyName() }}" class="brand-mark-logo">
@endif
@if($branding->hasIcon())
    <img src="{{ $branding->iconUrl() }}" alt="{{ $branding->companyName() }}" class="brand-mark-icon">
@else
    <i class="bi bi-headset text-primary nav-icon fs-5" aria-hidden="true"></i>
@endif
