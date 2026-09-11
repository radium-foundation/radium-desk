<?php

namespace App\Console\Commands;

use App\Models\InventoryCustomer;
use App\Services\Pos\AdminPosCustomerImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:import-admin-pos-customers {source : JSON file of Admin POS user rows} {--dry-run : Classify and preview without writing} {--execute : INSERT new A/B customers only} {--manifest= : Optional JSON manifest path}')]
#[Description('One-time Admin POS customer import into inventory_customers. Reads a JSON file; never connects to radiumbox_prod.')]
class ImportAdminPosCustomersCommand extends Command
{
    public function handle(AdminPosCustomerImporter $importer): int
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

        $existing = InventoryCustomer::query()
            ->get(['id', 'name', 'phone', 'email', 'gstin'])
            ->map(fn (InventoryCustomer $customer): array => [
                'id' => $customer->id,
                'phone' => $customer->phone,
                'name' => $customer->name,
                'email' => $customer->email,
                'gstin' => $customer->gstin,
            ])
            ->all();

        $result = $execute
            ? $importer->execute($decoded)
            : $importer->preview($decoded, $existing);

        $counts = $result['counts'];
        $this->line('mode='.($execute ? 'execute' : 'dry-run'));
        $this->line('source='.$counts['source']);
        $this->line('A='.$counts['A']);
        $this->line('B='.$counts['B']);
        $this->line('C='.$counts['C']);
        $this->line('D='.$counts['D']);
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
                    'target_id' => $row['target_id'],
                    'class' => $row['class'],
                    'action' => $row['action'],
                    'reason' => $row['reason'],
                    'phone_hash' => $row['phone_hash'],
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
