<?php

namespace App\Services\Finance;

use App\Services\Finance\Data\LegacyCashSourceRow;
use App\Support\Finance\LegacyCashContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LegacyCashSourceReader
{
    /**
     * @return Collection<int, LegacyCashSourceRow>
     */
    public function fromJson(string $path): Collection
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Legacy cash JSON fixture was not found.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Legacy cash JSON fixture is invalid.');
        }

        return $this->hydrate($decoded);
    }

    /**
     * @return Collection<int, LegacyCashSourceRow>
     */
    public function fromConnection(string $connection = LegacyCashContract::CONNECTION): Collection
    {
        $config = config('database.connections.'.$connection);
        if (! is_array($config) || empty($config['host']) || empty($config['database'])) {
            throw new RuntimeException(
                'Legacy RadiumBox connection is not configured. Set LEGACY_RADIUMBOX_DB_HOST and LEGACY_RADIUMBOX_DB_DATABASE, or pass --json=. Credentials are never printed.',
            );
        }

        $rows = DB::connection($connection)
            ->table('expenses as e')
            ->leftJoin('admins as a', 'a.id', '=', 'e.created_by')
            ->orderBy('e.id')
            ->select([
                'e.id',
                'e.created_by',
                'e.amount',
                'e.type',
                'e.amount_type',
                'e.description',
                'e.created_at',
                'e.updated_at',
                'a.name as admin_name',
            ])
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        return $this->hydrate($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, LegacyCashSourceRow>
     */
    public function hydrate(array $rows): Collection
    {
        return collect($rows)->map(
            fn (array $row): LegacyCashSourceRow => LegacyCashSourceRow::fromArray($row),
        )->values();
    }
}
