<?php

namespace App\Services;

use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;

class DeviceModelAliasResolver
{
    /** @var array<string, DeviceModel>|null */
    private ?array $aliasLookup = null;

    /** @var array<string, DeviceModel>|null */
    private ?array $codeLookup = null;

    public function __construct(
        private readonly DeviceModelAliasNormalizer $normalizer,
    ) {}

    public function normalize(string $value): string
    {
        return $this->normalizer->normalize($value);
    }

    public function resolve(?string $incoming): ?DeviceModel
    {
        if (! filled($incoming)) {
            return null;
        }

        $normalized = $this->normalize($incoming);

        if ($normalized === '') {
            return null;
        }

        if ($this->aliasLookup !== null || $this->codeLookup !== null) {
            return $this->aliasLookup[$normalized]
                ?? $this->codeLookup[$normalized]
                ?? null;
        }

        return $this->resolveByAlias($incoming)
            ?? $this->resolveByCode($incoming);
    }

    public function resolveByAlias(string $alias): ?DeviceModel
    {
        $normalized = $this->normalize($alias);

        if ($normalized === '') {
            return null;
        }

        if ($this->aliasLookup !== null) {
            return $this->aliasLookup[$normalized] ?? null;
        }

        $record = DeviceModelAlias::query()
            ->where('normalized_alias', $normalized)
            ->with('deviceModel')
            ->first();

        $deviceModel = $record?->deviceModel;

        return $deviceModel?->is_active ? $deviceModel : null;
    }

    public function resolveByCode(string $code): ?DeviceModel
    {
        $normalized = $this->normalize($code);

        if ($normalized === '') {
            return null;
        }

        if ($this->codeLookup !== null) {
            return $this->codeLookup[$normalized] ?? null;
        }

        return DeviceModel::query()
            ->where('is_active', true)
            ->whereNotNull('code')
            ->get(['id', 'name', 'code', 'brand', 'is_active'])
            ->first(fn (DeviceModel $model): bool => $this->normalize((string) $model->code) === $normalized);
    }

    public function warmLookup(): void
    {
        $this->aliasLookup = [];
        $this->codeLookup = [];

        DeviceModelAlias::query()
            ->with(['deviceModel' => fn ($query) => $query->where('is_active', true)])
            ->get(['id', 'device_model_id', 'normalized_alias'])
            ->each(function (DeviceModelAlias $alias): void {
                if ($alias->deviceModel === null) {
                    return;
                }

                $this->aliasLookup[$alias->normalized_alias] ??= $alias->deviceModel;
            });

        DeviceModel::query()
            ->where('is_active', true)
            ->whereNotNull('code')
            ->get(['id', 'name', 'code', 'brand', 'is_active'])
            ->each(function (DeviceModel $model): void {
                $normalizedCode = $this->normalize((string) $model->code);

                if ($normalizedCode === '') {
                    return;
                }

                $this->codeLookup[$normalizedCode] ??= $model;
            });
    }
}
