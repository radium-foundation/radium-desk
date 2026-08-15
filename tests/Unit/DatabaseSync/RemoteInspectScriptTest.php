<?php

namespace Tests\Unit\DatabaseSync;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoteInspectScriptTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing/probe-'.uniqid('', true).'.sqlite');
        touch($this->databasePath);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate');
    }

    protected function tearDown(): void
    {
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_remote_inspect_script_returns_read_only_table_stats(): void
    {
        DB::table('users')->insert([
            'name' => 'Probe User',
            'email' => 'probe@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->runInspectScript([
            '--action=table-stats',
            '--table=users',
            '--strategy=bigint_id+updated_at',
            '--primary-key=id',
            '--updated-at=updated_at',
            '--created-at=created_at',
        ]);

        $this->assertSame(1, $payload['count'] ?? null);
        $this->assertSame(1, $payload['max_primary_key'] ?? null);
        $this->assertNotEmpty($payload['max_updated_at'] ?? null);
    }

    public function test_remote_inspect_script_returns_migration_status(): void
    {
        $payload = $this->runInspectScript(['--action=migration-status']);

        $this->assertIsArray($payload['migrations'] ?? null);
        $this->assertNotEmpty($payload['migrations']);
    }

    public function test_remote_inspect_script_does_not_mutate_migration_rows(): void
    {
        $before = DB::table('migrations')->count();

        $this->runInspectScript(['--action=migration-status']);

        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasTable('lcds_probe_should_not_create'));
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function runInspectScript(array $arguments): array
    {
        $script = base_path('app/Infrastructure/DatabaseSync/Scripts/remote_inspect.php');
        $command = sprintf(
            'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=%s php %s %s',
            escapeshellarg($this->databasePath),
            escapeshellarg($script),
            implode(' ', array_map(static fn (string $argument): string => escapeshellarg($argument), $arguments)),
        );

        $output = shell_exec($command);
        $this->assertIsString($output);

        $payload = json_decode(trim($output), true);
        $this->assertIsArray($payload, 'Unexpected inspect output: '.$output);

        if (isset($payload['error'])) {
            $this->fail('Inspect script error: '.$payload['error']);
        }

        return $payload;
    }
}
