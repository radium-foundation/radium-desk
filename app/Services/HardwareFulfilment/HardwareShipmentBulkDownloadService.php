<?php

namespace App\Services\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\Shipment;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareBulkDownloadOutcome;
use App\Services\HardwareFulfilment\Data\HardwareBulkDownloadRow;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\Inventory\InventoryBranchScope;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

class HardwareShipmentBulkDownloadService
{
    public const MODE_LABELS = 'labels';

    public const MODE_MANIFESTS = 'manifests';

    public const MODE_PACKAGE = 'package';

    public function __construct(
        private readonly HardwareCarrierDocumentFetcher $fetcher,
    ) {}

    /**
     * @param  list<int>  $fulfilmentIds
     */
    public function download(string $mode, array $fulfilmentIds, ?User $actor = null): HardwareBulkDownloadOutcome
    {
        $orderedIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $fulfilmentIds)));
        if ($orderedIds === []) {
            throw ValidationException::withMessages([
                'fulfilment_ids' => 'Select at least one hardware fulfilment.',
            ]);
        }

        $fulfilments = HardwareFulfilment::query()
            ->whereIn('id', $orderedIds)
            ->with(['shipment'])
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($orderedIds as $id) {
            $fulfilment = $fulfilments->get($id);
            if ($fulfilment === null) {
                $rows[] = new HardwareBulkDownloadRow(
                    fulfilmentId: $id,
                    sourceId: (string) $id,
                    awb: null,
                    carrier: null,
                    shipmentStatus: null,
                    labelAvailable: false,
                    manifestAvailable: false,
                    manifestIdentity: null,
                    result: 'excluded',
                    exclusionReason: 'Fulfilment not found.',
                );

                continue;
            }

            $reason = $this->accessExclusionReason($fulfilment, $actor);
            if ($reason !== null) {
                $rows[] = $this->rowFromFulfilment($fulfilment, 'excluded', $reason);

                continue;
            }

            $rows[] = $this->rowFromFulfilment($fulfilment, 'pending');
        }

        $tmpdir = $this->makeWorkDir();
        $zipPath = $tmpdir.DIRECTORY_SEPARATOR.'archive.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages([
                'download' => 'Unable to prepare the download archive.',
            ]);
        }

        $manifestIndex = [];
        $includedAny = false;

        foreach ($rows as $index => $row) {
            if ($row->result !== 'pending') {
                continue;
            }

            $fulfilment = $fulfilments->get($row->fulfilmentId);
            if ($fulfilment === null) {
                continue;
            }

            $shipment = $this->boundShipment($fulfilment);
            $failure = null;
            $addedForRow = false;

            if ($mode === self::MODE_LABELS || $mode === self::MODE_PACKAGE) {
                $labelFile = $this->resolveLabelEntry($fulfilment, $shipment);
                if ($labelFile === null) {
                    if ($mode === self::MODE_LABELS) {
                        $failure = 'Missing or unavailable shipping label.';
                    }
                } else {
                    $entry = 'labels/'.$this->safeFilename($row->sourceId, $row->awb ?? 'nolabel', 'label').'.pdf';
                    if ($zip->addFromString($entry, $labelFile['bytes'])) {
                        $includedAny = true;
                        $addedForRow = true;
                    } else {
                        $failure = 'Label could not be added to the archive.';
                    }
                }
            }

            if ($failure === null && ($mode === self::MODE_MANIFESTS || $mode === self::MODE_PACKAGE)) {
                $manifestFile = $this->resolveManifestEntry($fulfilment, $shipment);
                if ($manifestFile === null) {
                    if ($mode === self::MODE_MANIFESTS) {
                        $failure = 'Missing or unavailable manifest.';
                    }
                } else {
                    $identity = $manifestFile['identity'];
                    if (! isset($manifestIndex[$identity])) {
                        $entry = 'manifests/'.$this->safeFilename(
                            $identity,
                            $row->carrier ?? 'manifest',
                            'manifest',
                        ).'.pdf';
                        if ($zip->addFromString($entry, $manifestFile['bytes'])) {
                            $manifestIndex[$identity] = $entry;
                            $includedAny = true;
                            $addedForRow = true;
                        } else {
                            $failure = 'Manifest could not be added to the archive.';
                        }
                    } else {
                        $addedForRow = true;
                    }
                }
            }

            if ($failure !== null) {
                $rows[$index] = $this->rowFromFulfilment($fulfilment, 'failed', downloadFailure: $failure);
            } elseif ($addedForRow) {
                $rows[$index] = $this->rowFromFulfilment($fulfilment, 'included');
            } elseif ($mode === self::MODE_PACKAGE) {
                $rows[$index] = $this->rowFromFulfilment($fulfilment, 'failed', downloadFailure: 'No label or manifest available.');
            } elseif ($mode === self::MODE_MANIFESTS || $mode === self::MODE_LABELS) {
                $rows[$index] = $this->rowFromFulfilment($fulfilment, 'failed', downloadFailure: 'No document available for this mode.');
            }
        }

        if ($mode === self::MODE_PACKAGE) {
            $zip->addFromString('reconciliation.csv', $this->buildReconciliationCsv($rows));
        }
        $zip->close();

        if (! $includedAny) {
            File::deleteDirectory($tmpdir);

            return new HardwareBulkDownloadOutcome(
                rows: array_values($rows),
                archivePath: null,
                archiveFilename: $this->archiveFilename($mode),
            );
        }

        return new HardwareBulkDownloadOutcome(
            rows: array_values($rows),
            archivePath: $zipPath,
            archiveFilename: $this->archiveFilename($mode),
        );
    }

    /**
     * @param  list<HardwareBulkDownloadRow>  $rows
     */
    public function buildReconciliationCsv(array $rows): string
    {
        $lines = [[
            'fulfilment_id',
            'source_order_id',
            'awb',
            'carrier',
            'shipment_status',
            'label_available',
            'manifest_identity',
            'manifest_available',
            'result',
            'exclusion_reason',
            'download_failure',
        ]];

        foreach ($rows as $row) {
            $lines[] = [
                (string) $row->fulfilmentId,
                $row->sourceId,
                $row->awb ?? '',
                $row->carrier ?? '',
                $row->shipmentStatus ?? '',
                $row->labelAvailable ? 'yes' : 'no',
                $row->manifestIdentity ?? '',
                $row->manifestAvailable ? 'yes' : 'no',
                $row->result,
                $row->exclusionReason ?? '',
                $row->downloadFailure ?? '',
            ];
        }

        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            return '';
        }

        foreach ($lines as $line) {
            fputcsv($buffer, $line);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        return is_string($csv) ? $csv : '';
    }

    private function rowFromFulfilment(
        HardwareFulfilment $fulfilment,
        string $result,
        ?string $exclusionReason = null,
        ?string $downloadFailure = null,
    ): HardwareBulkDownloadRow {
        $shipment = $this->boundShipment($fulfilment);

        return new HardwareBulkDownloadRow(
            fulfilmentId: (int) $fulfilment->id,
            sourceId: (string) $fulfilment->source_id,
            awb: $shipment?->awb,
            carrier: $shipment?->courier_name ?? $shipment?->provider,
            shipmentStatus: $shipment?->status?->value ?? (string) $shipment?->status,
            labelAvailable: $this->hasLabel($shipment),
            manifestAvailable: $this->hasManifest($shipment),
            manifestIdentity: $this->manifestIdentity($shipment),
            result: $result,
            exclusionReason: $exclusionReason,
            downloadFailure: $downloadFailure,
        );
    }

    private function accessExclusionReason(HardwareFulfilment $fulfilment, ?User $actor): ?string
    {
        if ($actor === null || ! HardwareFulfilmentAccess::allowsDocumentDownload($actor)) {
            return 'Not authorized to download fulfilment documents.';
        }

        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            return 'Frozen pending hardware order.';
        }

        if (HardwareFulfilmentAccess::allows($actor) && $fulfilment->fulfilment_branch_id !== null) {
            $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
            if ($branch === null) {
                return 'Hardware fulfilment branch is missing.';
            }

            try {
                InventoryBranchScope::assertCanOperate($actor, $branch);
            } catch (HttpException) {
                return 'Not authorized for this fulfilment branch.';
            }
        }

        return null;
    }

    private function boundShipment(HardwareFulfilment $fulfilment): ?Shipment
    {
        $shipment = $fulfilment->shipment;
        if ($shipment === null && $fulfilment->shipment_id !== null) {
            $shipment = Shipment::query()->find($fulfilment->shipment_id);
        }
        if ($shipment === null) {
            $shipment = Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->first();
        }

        return $shipment;
    }

    private function hasLabel(?Shipment $shipment): bool
    {
        if ($shipment === null) {
            return false;
        }

        if ($shipment->hasUploadedLabel()) {
            return true;
        }

        return filled($shipment->label_url);
    }

    private function hasManifest(?Shipment $shipment): bool
    {
        if ($shipment === null) {
            return false;
        }

        if ($shipment->manifest_path && $shipment->manifest_disk) {
            return true;
        }

        return filled($shipment->manifest_url) || filled($shipment->manifest_id);
    }

    private function manifestIdentity(?Shipment $shipment): ?string
    {
        if ($shipment === null) {
            return null;
        }

        if (filled($shipment->manifest_id)) {
            return (string) $shipment->manifest_id;
        }

        if (filled($shipment->manifest_url)) {
            return 'url:'.sha1((string) $shipment->manifest_url);
        }

        if (filled($shipment->manifest_path)) {
            return 'disk:'.sha1((string) $shipment->manifest_disk.':'.$shipment->manifest_path);
        }

        return null;
    }

    /**
     * @return array{bytes: string}|null
     */
    private function resolveLabelEntry(HardwareFulfilment $fulfilment, ?Shipment $shipment): ?array
    {
        if ($shipment === null) {
            return null;
        }

        if ($shipment->hasUploadedLabel()) {
            $local = $this->fetcher->readLocalDisk(
                (string) $shipment->label_disk,
                (string) $shipment->label_path,
            );

            return $local !== null ? ['bytes' => $local['bytes']] : null;
        }

        $url = trim((string) ($shipment->label_url ?? ''));
        if ($url === '') {
            return null;
        }

        $remote = $this->fetcher->fetchRemotePdf($url);

        return $remote !== null ? ['bytes' => $remote['bytes']] : null;
    }

    /**
     * @return array{bytes: string, identity: string}|null
     */
    private function resolveManifestEntry(HardwareFulfilment $fulfilment, ?Shipment $shipment): ?array
    {
        if ($shipment === null) {
            return null;
        }

        if (filled($shipment->manifest_disk) && filled($shipment->manifest_path)) {
            $local = $this->fetcher->readLocalDisk(
                (string) $shipment->manifest_disk,
                (string) $shipment->manifest_path,
            );
            if ($local !== null) {
                $identity = $this->manifestIdentity($shipment) ?? 'manifest';

                return ['bytes' => $local['bytes'], 'identity' => $identity];
            }
        }

        $url = trim((string) ($shipment->manifest_url ?? ''));
        if ($url !== '') {
            $remote = $this->fetcher->fetchRemotePdf($url);
            if ($remote !== null) {
                $identity = $this->manifestIdentity($shipment) ?? sha1($url);

                return ['bytes' => $remote['bytes'], 'identity' => $identity];
            }
        }

        return null;
    }

    private function safeFilename(string ...$parts): string
    {
        $joined = implode('_', array_filter($parts, static fn ($p) => (string) $p !== ''));

        return Str::limit(preg_replace('/[^A-Za-z0-9._-]+/', '_', $joined) ?? 'file', 120, '');
    }

    private function makeWorkDir(): string
    {
        $path = storage_path('app/temp/hardware-bulk-download/'.Str::uuid());
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function archiveFilename(string $mode): string
    {
        $stamp = now()->utc()->format('Ymd-His');

        return match ($mode) {
            self::MODE_LABELS => "hardware-labels-{$stamp}.zip",
            self::MODE_MANIFESTS => "hardware-manifests-{$stamp}.zip",
            default => "hardware-shipping-package-{$stamp}.zip",
        };
    }

    public function cleanupArchive(?string $archivePath): void
    {
        if ($archivePath === null) {
            return;
        }

        $dir = dirname($archivePath);
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
}
