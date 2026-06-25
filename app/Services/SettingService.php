<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SettingProduct;
use App\Models\SettingSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    private const CACHE_KEY = 'app.settings.all';

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->remember()[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, (string) $default);

        return (int) $value;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value],
        );

        $this->forget();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value === null ? null : (string) $value],
            );
        }

        $this->forget();
    }

    /**
     * @return array<string, string|null>
     */
    public function remember(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return Setting::query()
                ->pluck('value', 'key')
                ->all();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    public function enabledProductNames(): array
    {
        return SettingProduct::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @return Collection<int, SettingProduct>
     */
    public function allProducts(): Collection
    {
        return SettingProduct::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, SettingSource>
     */
    public function enabledSources(): Collection
    {
        return SettingSource::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    /**
     * @return Collection<int, SettingSource>
     */
    public function allSources(): Collection
    {
        return SettingSource::query()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function enabledSourceKeys(): array
    {
        return $this->enabledSources()->pluck('key')->all();
    }

    public function sourceIcon(string $key): string
    {
        $source = SettingSource::query()->where('key', $key)->first();

        if ($source !== null) {
            return $source->icon;
        }

        return \App\Enums\IncidentSource::tryFrom($key)?->icon() ?? 'bi-question-circle';
    }

    public function sourceLabel(string $key): string
    {
        $source = SettingSource::query()->where('key', $key)->first();

        if ($source !== null) {
            return $source->label;
        }

        return \App\Enums\IncidentSource::tryFrom($key)?->label() ?? ucfirst($key);
    }
}
