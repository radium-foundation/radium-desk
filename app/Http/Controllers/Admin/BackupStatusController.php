<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupCloudInventoryService;
use App\Services\Backup\BackupStatusService;
use App\Support\Administration\BackupAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BackupStatusController extends Controller
{
    public function __construct(
        private readonly BackupStatusService $backupStatusService,
        private readonly BackupCloudInventoryService $backupCloudInventoryService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(BackupAccess::canView($request->user()), 403);

        return view('admin.backups.index', [
            'status' => $this->backupStatusService->summary(),
            'cloudInventory' => $this->backupCloudInventoryService->summary(),
        ]);
    }
}
