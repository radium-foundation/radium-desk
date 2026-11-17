<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BulkHardwareFulfilmentDocumentsRequest;
use App\Services\HardwareFulfilment\Data\HardwareBulkDownloadOutcome;
use App\Services\HardwareFulfilment\Data\HardwareBulkDownloadRow;
use App\Services\HardwareFulfilment\HardwareShipmentBulkDownloadService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HardwareFulfilmentBulkDownloadController extends Controller
{
    public function __construct(
        private readonly HardwareShipmentBulkDownloadService $downloads,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allowsDocumentDownload($request->user()), 403);

            return $next($request);
        });
    }

    public function downloadLabels(BulkHardwareFulfilmentDocumentsRequest $request): StreamedResponse|JsonResponse
    {
        return $this->respond(
            $request,
            $this->downloads->download(
                HardwareShipmentBulkDownloadService::MODE_LABELS,
                $request->validated('fulfilment_ids'),
                $request->user(),
            ),
        );
    }

    public function downloadManifests(BulkHardwareFulfilmentDocumentsRequest $request): StreamedResponse|JsonResponse
    {
        return $this->respond(
            $request,
            $this->downloads->download(
                HardwareShipmentBulkDownloadService::MODE_MANIFESTS,
                $request->validated('fulfilment_ids'),
                $request->user(),
            ),
        );
    }

    public function downloadPackage(BulkHardwareFulfilmentDocumentsRequest $request): StreamedResponse|JsonResponse
    {
        return $this->respond(
            $request,
            $this->downloads->download(
                HardwareShipmentBulkDownloadService::MODE_PACKAGE,
                $request->validated('fulfilment_ids'),
                $request->user(),
            ),
        );
    }

    /**
     * @return StreamedResponse|JsonResponse
     */
    private function respond(BulkHardwareFulfilmentDocumentsRequest $request, HardwareBulkDownloadOutcome $outcome)
    {
        $payload = $this->serializeOutcome($outcome);

        if (! $outcome->hasArchive()) {
            return response()->json($payload, 422);
        }

        if ($request->wantsJson() && $request->boolean('reconcile_only')) {
            return response()->json($payload);
        }

        $path = $outcome->archivePath;
        abort_if($path === null, 500);

        return Response::streamDownload(function () use ($path): void {
            $stream = fopen($path, 'rb');
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }

            $this->downloads->cleanupArchive($path);
        }, $outcome->archiveFilename, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$outcome->archiveFilename.'"',
            'X-Hardware-Bulk-Download-Summary' => base64_encode(json_encode($payload) ?: '{}'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOutcome(HardwareBulkDownloadOutcome $outcome): array
    {
        return [
            'selected' => $outcome->selectedCount(),
            'included' => $outcome->includedCount(),
            'excluded' => $outcome->excludedCount(),
            'failed' => $outcome->failedCount(),
            'rows' => array_map(
                static fn (HardwareBulkDownloadRow $row): array => [
                    'fulfilment_id' => $row->fulfilmentId,
                    'source_id' => $row->sourceId,
                    'awb' => $row->awb,
                    'carrier' => $row->carrier,
                    'shipment_status' => $row->shipmentStatus,
                    'label_available' => $row->labelAvailable,
                    'manifest_available' => $row->manifestAvailable,
                    'manifest_identity' => $row->manifestIdentity,
                    'result' => $row->result,
                    'exclusion_reason' => $row->exclusionReason,
                    'download_failure' => $row->downloadFailure,
                ],
                $outcome->rows,
            ),
            'archive_ready' => $outcome->hasArchive(),
        ];
    }
}
