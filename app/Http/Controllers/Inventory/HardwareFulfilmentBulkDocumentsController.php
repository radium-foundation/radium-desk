<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BulkHardwareFulfilmentDocumentsRequest;
use App\Services\HardwareFulfilment\Data\HardwareBulkDocumentOutcome;
use App\Services\HardwareFulfilment\HardwareShipmentBulkDocumentsService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class HardwareFulfilmentBulkDocumentsController extends Controller
{
    public function __construct(
        private readonly HardwareShipmentBulkDocumentsService $bulkDocuments,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(HardwareFulfilmentAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function storeLabels(BulkHardwareFulfilmentDocumentsRequest $request): RedirectResponse|JsonResponse
    {
        $outcome = $this->bulkDocuments->downloadLabels(
            $request->validated('fulfilment_ids'),
            $request->user(),
        );

        return $this->respond($request, $outcome, 'labels');
    }

    public function storeManifest(BulkHardwareFulfilmentDocumentsRequest $request): RedirectResponse|JsonResponse
    {
        $outcome = $this->bulkDocuments->downloadManifest(
            $request->validated('fulfilment_ids'),
            $request->user(),
        );

        return $this->respond($request, $outcome, 'manifest');
    }

    private function respond(
        BulkHardwareFulfilmentDocumentsRequest $request,
        HardwareBulkDocumentOutcome $outcome,
        string $document,
    ): RedirectResponse|JsonResponse {
        if ($outcome->downloadUrl === null) {
            return $this->failureResponse($request, $outcome, $document);
        }

        $payload = [
            'download_url' => $outcome->downloadUrl,
            'succeeded' => $outcome->succeededSourceIds,
            'excluded' => $outcome->excluded,
            'failed' => $outcome->failed,
            'partial' => $outcome->isPartial(),
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        $redirect = redirect()->away($outcome->downloadUrl);

        if ($outcome->isPartial()) {
            return $redirect
                ->with('hardware_bulk_document_partial', $payload)
                ->with('status', $this->partialStatusMessage($outcome, $document));
        }

        return $redirect;
    }

    private function failureResponse(
        BulkHardwareFulfilmentDocumentsRequest $request,
        HardwareBulkDocumentOutcome $outcome,
        string $document,
    ): RedirectResponse|JsonResponse {
        $payload = [
            'download_url' => null,
            'succeeded' => $outcome->succeededSourceIds,
            'excluded' => $outcome->excluded,
            'failed' => $outcome->failed,
            'partial' => true,
        ];

        if ($request->wantsJson()) {
            return response()->json($payload, 422);
        }

        return redirect()
            ->back()
            ->withErrors([
                'shipping' => $this->failureMessage($outcome, $document),
            ])
            ->with('hardware_bulk_document_partial', $payload);
    }

    private function partialStatusMessage(HardwareBulkDocumentOutcome $outcome, string $document): string
    {
        $label = $document === 'labels' ? 'labels' : 'manifest';

        return sprintf(
            'Bulk %s prepared for %d order(s). %d excluded.',
            $label,
            $outcome->succeededCount(),
            count($outcome->excluded),
        );
    }

    private function failureMessage(HardwareBulkDocumentOutcome $outcome, string $document): string
    {
        $reasons = array_merge(
            array_map(static fn (array $row): string => $row['source_id'].': '.$row['reason'], $outcome->excluded),
            array_map(static fn (array $row): string => $row['source_id'].': '.$row['reason'], $outcome->failed),
        );

        return 'Bulk '.$document.' failed. '.implode('; ', $reasons);
    }
}
