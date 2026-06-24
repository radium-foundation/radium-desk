<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardServiceCaseController extends Controller
{
    public function row(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $incident->load(['order.transactionAssigner', 'creator']);

        $canManageTransactions = $request->user()?->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]) ?? false;

        return response()->json([
            'incident_id' => $incident->id,
            'html' => view('dashboard.partials.service-case-row', [
                'serviceCase' => $incident,
                'canManageTransactions' => $canManageTransactions,
                'canSelectRows' => $canManageTransactions,
            ])->render(),
        ]);
    }
}
