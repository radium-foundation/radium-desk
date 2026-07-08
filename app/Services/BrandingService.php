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
        return $this->assetUrlFromSetting('general.logo_path');
    }

    public function faviconUrl(): ?string
    {
        $configured = $this->assetUrlFromSetting('general.favicon_path');

        if ($configured !== null) {
            return $configured;
        }

        foreach (['favicon.ico', 'favicon.svg', 'favicon.png'] as $filename) {
            $path = public_path($filename);

            if (is_file($path) && filesize($path) > 0) {
                return asset($filename);
            }
        }

        return null;
    }

    public function hasLogo(): bool
    {
        return $this->logoUrl() !== null;
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

        if (is_file(public_path($path))) {
            return asset($path);
        }

        return null;
    }
}
