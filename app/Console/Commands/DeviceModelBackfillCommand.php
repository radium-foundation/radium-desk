<?php

namespace App\Console\Commands;

use App\Models\DeviceModel;
use App\Models\Order;
use App\Services\AutomationIdentityService;
use App\Services\OrderDeviceModelService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('device-models:backfill
    {--dry-run : Preview matches without writing changes (default unless --force)}
    {--force : Apply assignments (required to write; still prompts for confirmation)}
    {--limit= : Maximum number of orders to process}
    {--order= : Process a single order by order_id}')]
#[Description('Backfill Order.device_model_id from legacy free-text device_model values')]
class DeviceModelBackfillCommand extends Command
{
    private int $processed = 0;

    private int $matched = 0;

    private int $assigned = 0;

    private int $alreadyAssigned = 0;

    private int $unmatched = 0;

    private int $errors = 0;

    /** @var array<string, int> */
    private array $unmatchedModels = [];

    public function __construct(
        private readonly OrderDeviceModelService $orderDeviceModelService,
        private readonly AutomationIdentityService $automationIdentityService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('force') && $this->option('dry-run')) {
            $this->error('Cannot combine --force and --dry-run.');

            return self::FAILURE;
        }

        // Production-safe default: dry-run unless --force is supplied.
        $dryRun = ! (bool) $this->option('force');
        $limit = $this->resolveLimit();
        $orderId = $this->resolveOrderId();

        if ($dryRun) {
            $this->info('Dry run — no changes will be written. Pass --force to apply assignments.');
        }

        $lookup = $this->buildDeviceModelLookup();

        if ($orderId !== null) {
            $order = Order::query()->where('order_id', $orderId)->first();

            if ($order === null) {
                $this->error("Order not found: {$orderId}");

                return self::FAILURE;
            }

            if (! $dryRun && ! $this->confirmExecution(1)) {
                return self::SUCCESS;
            }

            $this->processOrder($order, $lookup, $dryRun);
            $this->renderSummary($dryRun);
            $this->logCompletion($dryRun, $limit, $orderId);

            return self::SUCCESS;
        }

        $pendingCount = $this->qualifyingOrdersQuery()->count();

        if ($limit !== null) {
            $pendingCount = min($pendingCount, $limit);
        }

        if ($pendingCount === 0) {
            $this->info('No orders require device model backfill.');
            $this->renderSummary($dryRun);

            return self::SUCCESS;
        }

        if (! $dryRun && ! $this->confirmExecution($pendingCount)) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing up to %d legacy order(s)%s.',
            $pendingCount,
            $dryRun ? ' (dry run)' : '',
        ));

        $this->qualifyingOrdersQuery()
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->cursor()
            ->each(function (Order $order) use ($lookup, $dryRun): void {
                $this->processOrder($order, $lookup, $dryRun);
            });

        $this->renderSummary($dryRun);
        $this->logCompletion($dryRun, $limit, null);

        return self::SUCCESS;
    }

    private function logCompletion(bool $dryRun, ?int $limit, ?string $orderId): void
    {
        Log::info('Device model backfill command completed.', [
            'dry_run' => $dryRun,
            'force' => (bool) $this->option('force'),
            'limit' => $limit,
            'order' => $orderId,
            'processed' => $this->processed,
            'matched' => $this->matched,
            'assigned' => $this->assigned,
            'already_assigned' => $this->alreadyAssigned,
            'unmatched' => $this->unmatched,
            'errors' => $this->errors,
            'unmatched_models' => $this->unmatchedModels,
        ]);
    }

    private function confirmExecution(int $pendingCount): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        if ($this->confirm(sprintf(
            'You are about to assign device models to %d order(s). Continue?',
            $pendingCount,
        ))) {
            return true;
        }

        $this->info('Backfill cancelled.');

        return false;
    }

    /**
     * @param  array{by_name: array<string, DeviceModel>, by_code: array<string, DeviceModel>}  $lookup
     */
    private function processOrder(Order $order, array $lookup, bool $dryRun): void
    {
        $this->processed++;

        if ($order->hasDeviceModelAssigned()) {
            $this->alreadyAssigned++;

            return;
        }

        $rawModel = (string) $order->device_model;
        $normalized = $this->normalizeModelLabel($rawModel);

        if ($normalized === '') {
            $this->unmatched++;
            $this->recordUnmatched($rawModel);

            return;
        }

        $deviceModel = $lookup['by_name'][$normalized]
            ?? $lookup['by_code'][$normalized]
            ?? null;

        if ($deviceModel === null) {
            $this->unmatched++;
            $this->recordUnmatched($rawModel);

            return;
        }

        $this->matched++;

        if ($dryRun) {
            $this->assigned++;

            return;
        }

        try {
            $this->orderDeviceModelService->assignDeviceModel(
                $order,
                $deviceModel,
                $this->automationIdentityService->systemUser(),
                isBulk: true,
            );
            $this->assigned++;
        } catch (Throwable $exception) {
            $this->errors++;
            $this->error(sprintf(
                'Failed to assign device model for order %s: %s',
                $order->order_id,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array{by_name: array<string, DeviceModel>, by_code: array<string, DeviceModel>}
     */
    private function buildDeviceModelLookup(): array
    {
        /** @var Collection<int, DeviceModel> $models */
        $models = DeviceModel::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'code', 'is_active']);

        $byName = [];
        $byCode = [];

        foreach ($models as $model) {
            $nameKey = $this->normalizeModelLabel($model->name);

            if ($nameKey !== '' && ! array_key_exists($nameKey, $byName)) {
                $byName[$nameKey] = $model;
            }

            if (! filled($model->code)) {
                continue;
            }

            $codeKey = $this->normalizeModelLabel((string) $model->code);

            if ($codeKey !== '' && ! array_key_exists($codeKey, $byCode)) {
                $byCode[$codeKey] = $model;
            }
        }

        return [
            'by_name' => $byName,
            'by_code' => $byCode,
        ];
    }

    private function normalizeModelLabel(string $label): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($label)) ?? trim($label);

        return Str::lower($collapsed);
    }

    private function recordUnmatched(string $rawModel): void
    {
        $display = trim($rawModel) === '' ? '(empty)' : trim($rawModel);
        $this->unmatchedModels[$display] = ($this->unmatchedModels[$display] ?? 0) + 1;
    }

    private function qualifyingOrdersQuery(): Builder
    {
        return Order::query()
            ->whereNull('device_model_id')
            ->whereNotNull('device_model')
            ->where('device_model', '!=', '');
    }

    private function renderSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Device model backfill summary');
        $this->line('Processed: '.$this->processed);
        $this->line('Matched: '.$this->matched);
        $this->line(($dryRun ? 'Would assign' : 'Assigned').': '.$this->assigned);
        $this->line('Already assigned: '.$this->alreadyAssigned);
        $this->line('Unmatched: '.$this->unmatched);
        $this->line('Errors: '.$this->errors);

        if ($this->unmatchedModels === []) {
            return;
        }

        $this->newLine();
        $this->info('Unmatched models (grouped by text):');

        arsort($this->unmatchedModels);

        foreach ($this->unmatchedModels as $text => $count) {
            $this->line(sprintf('- %s (%d)', $text, $count));
        }
    }

    private function resolveLimit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        $parsed = (int) $limit;

        return $parsed > 0 ? $parsed : null;
    }

    private function resolveOrderId(): ?string
    {
        $orderId = $this->option('order');

        if (! is_string($orderId) || trim($orderId) === '') {
            return null;
        }

        return trim($orderId);
    }
}
