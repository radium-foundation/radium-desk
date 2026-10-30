<?php

namespace App\Services\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageChannelReport;
use App\Data\StatutoryCoverage\StatutoryCoverageReport;
use App\Data\StatutoryCoverage\StatutoryCoverageSourcePopulation;
use App\Data\StatutoryCoverage\StatutoryCoverageSpokeObligationReference;
use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;
use App\Enums\StatutoryInvoiceChannel;
use Illuminate\Support\Carbon;

final class StatutoryCoverageReconciliationService
{
    public function __construct(
        private readonly StatutoryCoverageSpokeObligationLister $obligationLister,
        private readonly StatutoryCoverageDeskReferenceBuilder $deskReferences,
        private readonly StatutoryCoverageOrderClassifier $classifier,
        private readonly StatutoryCoverageWatchdogAssessment $watchdog,
    ) {}

    /**
     * @param  list<string>|null  $channelFilter
     */
    public function reconcile(
        ?array $channelFilter,
        StatutoryCoverageWindow $window,
        int $page,
        int $perPage,
        bool $includeExceptions,
        int $maxExceptions,
    ): StatutoryCoverageReport {
        $channels = $this->resolveChannels($channelFilter);
        $channelReports = [];

        foreach ($channels as $channel) {
            $channelReports[] = $this->reconcileChannel(
                $channel,
                $window,
                $page,
                $perPage,
                $includeExceptions,
                $maxExceptions,
            );
        }

        return new StatutoryCoverageReport(
            generatedAt: Carbon::now($window->timezone),
            dryRun: true,
            channels: $channelReports,
            watchdogSuggestions: $this->watchdog->suggestAlertKeys($channelReports),
        );
    }

    /**
     * @return list<StatutoryInvoiceChannel>
     */
    private function resolveChannels(?array $channelFilter): array
    {
        $configured = array_keys(config('statutory_coverage.channels', []));
        $resolved = [];

        foreach ($configured as $key) {
            if (! is_string($key)) {
                continue;
            }
            if ($channelFilter !== null && $channelFilter !== [] && ! in_array($key, $channelFilter, true)) {
                continue;
            }
            $enum = StatutoryInvoiceChannel::tryFrom($key);
            if ($enum !== null) {
                $resolved[] = $enum;
            }
        }

        return $resolved;
    }

    private function reconcileChannel(
        StatutoryInvoiceChannel $channel,
        StatutoryCoverageWindow $window,
        int $page,
        int $perPage,
        bool $includeExceptions,
        int $maxExceptions,
    ): StatutoryCoverageChannelReport {
        $config = config('statutory_coverage.channels.'.$channel->value, []);
        $label = is_array($config) ? (string) ($config['label'] ?? $channel->value) : $channel->value;

        $sourceFetch = $this->obligationLister->collectSourcePopulation(
            $channel,
            $window,
            $perPage,
        );
        /** @var StatutoryCoverageSourcePopulation $sourcePopulation */
        $sourcePopulation = $sourceFetch['population'];
        $sourceIds = $sourceFetch['source_ids'];
        /** @var array<string, StatutoryCoverageSpokeObligationReference> $sourceReferences */
        $sourceReferences = $sourceFetch['references'];
        $defaultSourceType = (string) config('statutory_coverage.default_source_type', 'commerce_order');

        $deskBatch = $this->deskReferences->collectIds($channel, $window, $page, $perPage);
        $deskIds = $deskBatch['ids'];

        $allIds = collect($sourceIds)
            ->merge($deskIds)
            ->unique()
            ->values();

        $counts = [];
        foreach (StatutoryCoverageClassification::cases() as $case) {
            if ($case === StatutoryCoverageClassification::SourcePopulationUnavailable) {
                continue;
            }
            $counts[$case->value] = 0;
        }

        $exceptions = [];
        foreach ($allIds as $sourceId) {
            $fromSource = in_array($sourceId, $sourceIds, true);
            $fromDesk = in_array($sourceId, $deskIds, true);
            $refKey = strtoupper($channel->value).'|'.$defaultSourceType.'|'.strtoupper(trim($sourceId));
            $obligationClass = $sourceReferences[$refKey]->obligationClass ?? null;
            $row = $this->classifier->classify(
                $channel,
                $sourceId,
                $fromDesk,
                $fromSource,
                $obligationClass,
            );
            $counts[$row->classification->value] = ($counts[$row->classification->value] ?? 0) + 1;

            if ($includeExceptions && $row->classification !== StatutoryCoverageClassification::IssuedSynchronized) {
                if (count($exceptions) < $maxExceptions) {
                    $exceptions[] = $row;
                }
            }
        }

        $deskComplete = (bool) $deskBatch['complete'];
        $sourceComplete = $sourcePopulation->supported
            && $sourcePopulation->authoritativeComplete
            && $sourcePopulation->failureReason === null;

        $reportComplete = $deskComplete && $sourceComplete;

        if (! $sourcePopulation->supported) {
            $partialReason = $sourcePopulation->failureReason ?? 'Spoke obligation list API is not registered for this channel.';
        } elseif (! $sourceComplete) {
            $partialReason = $sourcePopulation->failureReason ?? 'Source population pagination or fetch incomplete.';
        } elseif (! $deskComplete) {
            $partialReason = $deskBatch['reason'] ?? 'Desk population pagination incomplete.';
        } else {
            $partialReason = null;
        }

        return new StatutoryCoverageChannelReport(
            channel: $channel->value,
            channelLabel: $label,
            window: $window,
            sourcePopulation: $sourcePopulation,
            deskPopulationCount: count($deskIds),
            deskPopulationTotal: (int) ($deskBatch['total'] ?? count($deskIds)),
            deskPopulationComplete: $deskComplete,
            sourcePopulationComplete: $sourceComplete,
            counts: $counts,
            exceptions: $exceptions,
            reportComplete: $reportComplete,
            partialReason: $partialReason,
        );
    }
}
