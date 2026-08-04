<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\OperationsWorkspacePanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsWorkspaceController extends Controller
{
    public function __construct(
        private readonly OperationsWorkspacePanelService $panelService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $payload = $this->panelService->renderEmbedded($request->user(), $request);

        return response()->json($payload);
    }
}
