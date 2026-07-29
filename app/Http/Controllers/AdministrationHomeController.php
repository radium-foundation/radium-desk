<?php

namespace App\Http\Controllers;

use App\Services\Operations\OperationsGmailHealthService;
use App\Services\Operations\OperationsIntegrationHealthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdministrationHomeController extends Controller
{
    public function __invoke(
        Request $request,
        OperationsIntegrationHealthService $integrationHealthService,
        OperationsGmailHealthService $gmailHealthService,
    ): View {
        $user = $request->user();

        abort_unless($user !== null, 403);
        abort_unless($user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]), 403);

        return view('admin.administration.index', [
            'integrationCards' => $integrationHealthService->cards(),
            'gmailHealth' => $gmailHealthService->widget(),
        ]);
    }
}
