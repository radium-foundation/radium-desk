<?php

namespace App\Console\Commands;

use App\Services\Pos\AdminPosCustomerBillingImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:import-admin-pos-customer-billing {source : JSON file of latest invoiced Admin POS snapshots} {--dry-run : Preview without writing} {--execute : INSERT missing billing profiles only} {--manifest= : Optional JSON manifest path}')]
#[Description('One-time last-known billing profiles for imported Admin POS customers. Reads a JSON file; never connects to radiumbox_prod.')]
class ImportAdminPosCustomerBillingCommand extends Command
{
    public function handle(AdminPosCustomerBillingImporter $importer): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && $this->option('dry-run')) {
            $this->error('Pass only one of --dry-run or --execute.');

            return self::FAILURE;
        }

        $sourcePath = (string) $this->argument('source');
        if (! is_file($sourcePath)) {
            $this->error('Source file not found.');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($sourcePath), true);
        if (! is_array($decoded)) {
            $this->error('Source JSON is invalid.');

            return self::FAILURE;
        }

        $result = $execute
            ? $importer->execute($decoded)
            : $importer->preview($decoded);

        $counts = $result['counts'];
        $this->line('mode='.($execute ? 'execute' : 'dry-run'));
        $this->line('source='.$counts['source']);
        $this->line('created='.$counts['created']);
        $this->line('matched='.$counts['matched']);
        $this->line('skipped='.$counts['skipped']);
        $this->line('review='.$counts['review']);

        $manifestPath = $this->option('manifest');
        if (is_string($manifestPath) && $manifestPath !== '') {
            $safeRows = array_map(static function (array $row): array {
                return [
                    'legacy_source' => $row['legacy_source'],
                    'legacy_user_id' => $row['legacy_user_id'],
                    'legacy_order_id' => $row['legacy_order_id'],
                    'target_id' => $row['target_id'],
                    'action' => $row['action'],
                    'reason' => $row['reason'],
                ];
            }, $result['rows']);
            file_put_contents($manifestPath, json_encode([
                'counts' => $counts,
                'rows' => $safeRows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
            $this->line('manifest='.$manifestPath);
        }

        return self::SUCCESS;
    }
}
