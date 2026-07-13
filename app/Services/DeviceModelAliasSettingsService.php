<?php

namespace App\Services;

use App\Models\DeviceModel;
use App\Models\DeviceModelAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceModelAliasSettingsService
{
    public function __construct(
        private readonly DeviceModelAliasNormalizer $normalizer,
    ) {}

    public function paginated(?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        return DeviceModelAlias::query()
            ->with('deviceModel:id,name,code')
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->where(function ($innerQuery) use ($term): void {
                    $innerQuery->where('alias', 'like', $term)
                        ->orWhere('normalized_alias', 'like', $term)
                        ->orWhereHas('deviceModel', function ($deviceModelQuery) use ($term): void {
                            $deviceModelQuery->where('name', 'like', $term)
                                ->orWhere('code', 'like', $term);
                        });
                });
            })
            ->orderBy('alias')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(int $deviceModelId, string $alias): DeviceModelAlias
    {
        $normalizedAlias = $this->normalizer->normalize($alias);

        if ($normalizedAlias === '') {
            throw ValidationException::withMessages([
                'alias' => 'Alias must contain at least one alphanumeric character.',
            ]);
        }

        if (DeviceModelAlias::query()->where('normalized_alias', $normalizedAlias)->exists()) {
            throw ValidationException::withMessages([
                'alias' => 'This alias maps to an existing identity and cannot be duplicated.',
            ]);
        }

        return DB::transaction(fn (): DeviceModelAlias => DeviceModelAlias::query()->create([
            'device_model_id' => $deviceModelId,
            'alias' => trim($alias),
        ]));
    }

    public function update(DeviceModelAlias $deviceModelAlias, int $deviceModelId, string $alias): DeviceModelAlias
    {
        $normalizedAlias = $this->normalizer->normalize($alias);

        if ($normalizedAlias === '') {
            throw ValidationException::withMessages([
                'alias' => 'Alias must contain at least one alphanumeric character.',
            ]);
        }

        $duplicateExists = DeviceModelAlias::query()
            ->where('normalized_alias', $normalizedAlias)
            ->whereKeyNot($deviceModelAlias->id)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'alias' => 'This alias maps to an existing identity and cannot be duplicated.',
            ]);
        }

        $deviceModelAlias->update([
            'device_model_id' => $deviceModelId,
            'alias' => trim($alias),
        ]);

        return $deviceModelAlias->fresh(['deviceModel']);
    }

    public function delete(DeviceModelAlias $deviceModelAlias): void
    {
        $deviceModelAlias->delete();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function deviceModelOptions(): array
    {
        return DeviceModel::query()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DeviceModel $model): array => [
                'id' => $model->id,
                'name' => $model->name,
            ])
            ->all();
    }
}
