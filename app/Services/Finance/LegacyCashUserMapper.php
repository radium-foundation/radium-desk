<?php

namespace App\Services\Finance;

use App\Models\FinanceLegacyCashUserMap;
use App\Models\User;
use App\Services\Finance\Data\LegacyCashSourceRow;
use App\Support\Finance\LegacyCashContract;
use Illuminate\Support\Collection;

class LegacyCashUserMapper
{
    /**
     * @param  Collection<int, LegacyCashSourceRow>  $rows
     * @return array<string, FinanceLegacyCashUserMap>
     */
    public function sync(Collection $rows, bool $dryRun = false): array
    {
        $admins = [];
        foreach ($rows as $row) {
            $id = $row->createdBy;
            if ($id === '') {
                $id = 'unknown';
            }

            if (! isset($admins[$id])) {
                $admins[$id] = $row->adminName;
            } elseif ($admins[$id] === null && $row->adminName !== null) {
                $admins[$id] = $row->adminName;
            }
        }

        $maps = [];
        foreach ($admins as $legacyAdminId => $legacyName) {
            $resolved = $this->resolve((string) $legacyAdminId, $legacyName);
            if ($dryRun) {
                $maps[(string) $legacyAdminId] = $resolved;

                continue;
            }

            $maps[(string) $legacyAdminId] = FinanceLegacyCashUserMap::query()->updateOrCreate(
                [
                    'legacy_source' => LegacyCashContract::LEGACY_DATABASE,
                    'legacy_admin_id' => (string) $legacyAdminId,
                ],
                [
                    'legacy_admin_name' => $legacyName,
                    'desk_user_id' => $resolved->desk_user_id,
                    'mapping_status' => $resolved->mapping_status,
                    'notes' => $resolved->notes,
                ],
            );
        }

        return $maps;
    }

    public function resolve(string $legacyAdminId, ?string $legacyName): FinanceLegacyCashUserMap
    {
        $approved = LegacyCashContract::APPROVED_ADMIN_MAPS[$legacyAdminId] ?? null;
        $map = new FinanceLegacyCashUserMap([
            'legacy_source' => LegacyCashContract::LEGACY_DATABASE,
            'legacy_admin_id' => $legacyAdminId,
            'legacy_admin_name' => $legacyName,
            'desk_user_id' => null,
            'mapping_status' => FinanceLegacyCashUserMap::STATUS_UNMAPPED,
            'notes' => 'No approved Desk mapping for this Admin user.',
        ]);

        if ($approved === null) {
            return $map;
        }

        $matches = $this->findApprovedDeskUsers($approved);

        if ($matches->count() === 1) {
            $user = $matches->first();
            $map->desk_user_id = $user->id;
            $map->mapping_status = FinanceLegacyCashUserMap::STATUS_MAPPED;
            $map->notes = 'Approved name match to existing Desk user; numeric Admin id was not used.';

            return $map;
        }

        if ($matches->count() > 1) {
            $map->mapping_status = FinanceLegacyCashUserMap::STATUS_AMBIGUOUS;
            $map->notes = 'Approved name matched more than one Desk user; left unmapped.';

            return $map;
        }

        $map->notes = 'Approved mapping is configured but no unique Desk user was found.';

        return $map;
    }

    /**
     * @param  array{exact?: string, name_prefix?: string}  $rule
     * @return Collection<int, User>
     */
    private function findApprovedDeskUsers(array $rule): Collection
    {
        $query = User::query()->withTrashed();

        if (isset($rule['exact'])) {
            $query->where('name', $rule['exact']);
        } elseif (isset($rule['name_prefix'])) {
            $prefix = $rule['name_prefix'];
            $query->where(function ($inner) use ($prefix): void {
                $inner->where('name', $prefix)
                    ->orWhere('name', 'like', $prefix.' %');
            });
        } else {
            return collect();
        }

        return $query->orderBy('id')->get();
    }
}
