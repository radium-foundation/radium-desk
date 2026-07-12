<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class BrandingService
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function appName(): string
    {
        return (string) config('app.name', 'Radium Desk');
    }

    public function companyName(): string
    {
        $companyName = $this->settingService->get('general.company_name');

        if (is_string($companyName) && $companyName !== '') {
            return $companyName;
        }

        return $this->appName();
    }

    public function logoUrl(): ?string
    {
        return $this->assetUrlFromSetting('general.logo_path')
            ?? $this->defaultAssetUrl(config('branding.logo'));
    }

    public function iconUrl(): ?string
    {
        return $this->assetUrlFromSetting('general.icon_path')
            ?? $this->defaultAssetUrl(config('branding.icon'));
    }

    public function faviconUrl(): ?string
    {
        return $this->assetUrlFromSetting('general.favicon_path')
            ?? $this->defaultAssetUrl(config('branding.favicon'))
            ?? $this->legacyPublicFaviconUrl();
    }

    public function hasLogo(): bool
    {
        return $this->logoUrl() !== null;
    }

    public function hasIcon(): bool
    {
        return $this->iconUrl() !== null;
    }

    private function assetUrlFromSetting(string $key): ?string
    {
        $path = $this->settingService->get($key);

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return $this->defaultAssetUrl($path);
    }

    private function defaultAssetUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (! is_file(public_path($path))) {
            return null;
        }

        return asset($path);
    }

    private function legacyPublicFaviconUrl(): ?string
    {
        foreach (['favicon.ico', 'favicon.svg', 'favicon.png'] as $filename) {
            $path = public_path($filename);

            if (is_file($path) && filesize($path) > 0) {
                return asset($filename);
            }
        }

        return null;
    }
}
