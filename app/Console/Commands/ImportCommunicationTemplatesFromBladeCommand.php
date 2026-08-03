<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AutomationIdentityService;
use App\Services\CommunicationTemplates\CommunicationTemplateBladeImporter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;

class ImportCommunicationTemplatesFromBladeCommand extends Command
{
    protected $signature = 'communication-templates:import-blade
                            {--dry-run : Inventory only, do not write}
                            {--draft : Import as draft instead of approved}';

    protected $description = 'Import Blade notification templates into the Communication Template Store (Blade remains runtime).';

    public function handle(
        CommunicationTemplateBladeImporter $importer,
        AutomationIdentityService $automationIdentity,
    ): int {
        if ($this->option('dry-run')) {
            $rows = $importer->inventory();
            $this->table(
                ['Type', 'Name', 'Category', 'Blade', 'Exists', 'Imported'],
                collect($rows)->map(fn (array $row): array => [
                    $row['notification_type'],
                    $row['name'],
                    $row['category'],
                    $row['blade_view'],
                    $row['blade_exists'] ? 'yes' : 'no',
                    $row['imported'] ? 'yes' : 'no',
                ])->all(),
            );
            $this->info('Inventory count: '.count($rows));

            return self::SUCCESS;
        }

        $actor = User::query()
            ->role(RolePermissionSeeder::ROLE_SUPERADMIN)
            ->orderBy('id')
            ->first()
            ?? $automationIdentity->systemUser();

        $result = $importer->importAll($actor, approve: ! $this->option('draft'));

        $this->info("Imported: {$result['imported']}, skipped: {$result['skipped']}");
        $this->table(
            ['Type', 'Action', 'Key'],
            collect($result['rows'])->map(fn (array $row): array => [
                $row['notification_type'],
                $row['action'] ?? '',
                $row['template_key'] ?? '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
