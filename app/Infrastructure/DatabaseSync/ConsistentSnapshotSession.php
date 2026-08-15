<?php

namespace App\Infrastructure\DatabaseSync;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConsistentSnapshotSession
{
    private bool $open = false;

    /**
     * @return list<string>
     */
    public static function beginSql(string $driver): array
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return [
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            ];
        }

        return ['BEGIN'];
    }

    public function begin(): void
    {
        if ($this->open || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Consistent snapshot must start outside an existing transaction.');
        }

        $driver = DB::connection()->getDriverName();
        $pdo = DB::connection()->getPdo();

        foreach (self::beginSql($driver) as $sql) {
            if ($sql === 'BEGIN') {
                $pdo->beginTransaction();

                continue;
            }

            $pdo->exec($sql);
        }

        $this->open = true;
    }

    public function commit(): void
    {
        try {
            if ($this->open && DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->commit();
            }
        } finally {
            $this->open = false;
        }
    }

    public function rollBack(): void
    {
        try {
            if ($this->open && DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->rollBack();
            }
        } finally {
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
