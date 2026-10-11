<?php

namespace App\Console\Commands;

use App\Data\Cashfree\LegacyCashfreePaymentDiscoveryRow;
use App\Enums\LegacyCashfreePaymentDiscoveryClassification;
use App\Services\Cashfree\LegacyCashfreePaymentDiscoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

#[Description('Read-only discovery: legacy Desk orders with incomplete payment vs Cashfree PG (no writes)')]
class DiscoverLegacyCashfreeOrderPaymentsCommand extends Command
{
    protected $signature = 'cashfree:discover-legacy-order-payments
        {--limit=25 : Maximum Desk candidates to inspect (max 500)}
        {--legacy-only : Restrict to legacy-imported orders (default)}
        {--include-native : Also include native Desk orders missing payment authority}
        {--order=* : Restrict to specific business order id(s)}
        {--write= : Optional path to write JSON report (no secrets)}';

    public function __construct(
        private readonly LegacyCashfreePaymentDiscoveryService $discovery,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $legacyOnly = ! (bool) $this->option('include-native');

        /** @var list<string> $orders */
        $orders = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) $this->option('order'),
        )));

        $this->info('Read-only Cashfree legacy order payment discovery (no Desk/Cashfree mutations).');

        try {
            $report = $this->discovery->discover(
                limit: $limit,
                legacyOnly: $legacyOnly,
                businessOrderIds: $orders !== [] ? $orders : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Discovery failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $totals = $report->totalsByClassification();
        $this->newLine();
        $this->line('Total candidates inspected: '.count($report->rows));
        foreach (LegacyCashfreePaymentDiscoveryClassification::cases() as $case) {
            $count = $totals[$case->value] ?? 0;
            if ($count > 0) {
                $this->line(sprintf('  %s: %d', $case->value, $count));
            }
        }

        $this->newLine();
        $headers = [
            'desk_order_id',
            'desk_order_number',
            'legacy/native',
            'classification',
            'cashfree_amount',
            'cf_payment_id',
            'customer',
            'reason',
        ];

        $tableRows = array_map(
            static fn (LegacyCashfreePaymentDiscoveryRow $row): array => [
                $row->deskOrderId,
                $row->deskOrderNumber,
                $row->legacyNativeClassification,
                $row->classification->value,
                $row->cashfreeAmount ?? '-',
                $row->cashfreePaymentIds ?? '-',
                $row->customerCorrelation,
                mb_substr($row->reason, 0, 80),
            ],
            $report->rows,
        );

        $this->table($headers, $tableRows);

        $writePath = trim((string) $this->option('write'));
        if ($writePath !== '') {
            File::put($writePath, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Wrote JSON report to '.$writePath);
        }

        return self::SUCCESS;
    }
}
