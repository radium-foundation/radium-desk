<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SystemSettingsService
{
    private const CACHE_PREFIX = 'system_settings.key.';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $definition = $this->definition($key);
        $default ??= $definition['default'] ?? null;
        $type = $definition['type'] ?? 'string';

        $raw = Cache::rememberForever($this->cacheKey($key), function () use ($key, $default, $type): string {
            $row = SystemSetting::query()->where('key', $key)->first();

            if ($row === null || $row->value === null) {
                return $this->serializeValue($default, $type);
            }

            return (string) $row->value;
        });

        return $this->castValue($raw, $type);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function set(string $key, mixed $value, ?User $actor = null): SystemSetting
    {
        $definition = $this->definition($key);
        $type = $definition['type'] ?? 'string';
        $serialized = $this->serializeValue($value, $type);

        return DB::transaction(function () use ($key, $serialized, $actor): SystemSetting {
            $existing = SystemSetting::query()->where('key', $key)->first();
            $oldValue = $existing?->value;

            $setting = SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $serialized,
                    'updated_by' => $actor?->id,
                ],
            );

            $this->forget($key);

            if ($oldValue !== $serialized && $actor !== null) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'system_setting.updated',
                    auditable: $setting,
                    oldValues: ['key' => $key, 'value' => $oldValue],
                    newValues: ['key' => $key, 'value' => $serialized],
                );
            }

            return $setting->fresh(['updatedBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, User $actor): void
    {
        DB::transaction(function () use ($values, $actor): void {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $actor);
            }
        });
    }

    /**
     * @return Collection<string, Collection<int, array{
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     type: string,
     *     value: mixed,
     *     updated_at: \Illuminate\Support\Carbon|null,
     *     updated_by_name: string|null
     * }>>
     */
    public function groupedForAdmin(): Collection
    {
        $rows = SystemSetting::query()
            ->with('updatedBy')
            ->get()
            ->keyBy('key');

        $categories = collect(config('system_settings.categories', []))
            ->sortBy('sort')
            ->keys();

        $settings = collect(config('system_settings.settings', []));

        return $categories->mapWithKeys(function (string $categoryKey) use ($settings, $rows): array {
            $categorySettings = $settings
                ->filter(fn (array $definition): bool => ($definition['category'] ?? '') === $categoryKey)
                ->map(function (array $definition, string $key) use ($rows): array {
                    $row = $rows->get($key);

                    return [
                        'key' => $key,
                        'label' => $definition['label'],
                        'description' => $definition['description'] ?? null,
                        'type' => $definition['type'] ?? 'string',
                        'value' => $this->get($key, $definition['default'] ?? null),
                        'updated_at' => $row?->updated_at,
                        'updated_by_name' => $row?->updatedBy?->name,
                    ];
                })
                ->values();

            return [$categoryKey => $categorySettings];
        })->filter(fn (Collection $items): bool => $items->isNotEmpty());
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $key): array
    {
        $definitions = config('system_settings.settings', []);
        $definition = $definitions[$key] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown system setting [{$key}].");
        }

        return $definition;
    }

    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    public function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string) (int) $value,
            default => $value === null ? '' : (string) $value,
        };
    }

    private function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX.$key;
    }

    private function castValue(string $raw, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $raw,
            default => $raw,
        };
    }
}
