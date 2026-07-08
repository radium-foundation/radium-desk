<meta name="robots" content="noindex,nofollow">

@php($faviconUrl = app(\App\Services\BrandingService::class)->faviconUrl())
@if($faviconUrl)
    <link rel="icon" href="{{ $faviconUrl }}" type="{{ str_ends_with($faviconUrl, '.svg') ? 'image/svg+xml' : 'image/x-icon' }}">
@endif
