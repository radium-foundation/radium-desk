<?php

namespace App\Http\Controllers;

use App\Services\Administration\AdministrationSystemHealthSummaryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdministrationHomeController extends Controller
{
    public function __invoke(
        Request $request,
        AdministrationSystemHealthSummaryService $systemHealthSummary,
    ): View {
        $user = $request->user();

        abort_unless($user !== null, 403);
        abort_unless($user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]), 403);

        return view('admin.administration.index', [
            'systemHealthSummary' => $systemHealthSummary->summary(),
        ]);
    }
}
