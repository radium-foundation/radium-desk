<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardBatchTransactionSerialExportRequest;
use App\Services\BatchTransactionSerialExportService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DashboardBatchTransactionSerialExportController extends Controller
{
    public function __construct(
        private readonly BatchTransactionSerialExportService $exportService,
    ) {}

    public function __invoke(DashboardBatchTransactionSerialExportRequest $request): Response|SymfonyResponse
    {
        $incidentIds = array_map('intval', $request->input('incident_ids'));

        $serials = $this->exportService->serialNumbersForIncidents($incidentIds);
        if ($serials === []) {
            return response([
                'message' => 'No serial numbers are available for the selected orders.',
            ], 422);
        }

        $filename = $this->exportService->downloadFilename();
        $tmpdir = sys_get_temp_dir().'/rd-warranty-export-'.uniqid('', true);
        mkdir($tmpdir);
        $path = $tmpdir.'/'.$filename;

        try {
            $this->exportService->writeWorkbookForIncidents($incidentIds, $path);

            return response()->download(
                $path,
                $filename,
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
            )->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }
            @rmdir($tmpdir);

            throw $exception;
        }
    }
}
