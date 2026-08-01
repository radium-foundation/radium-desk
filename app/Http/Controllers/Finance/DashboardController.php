<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_DASHBOARD_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function __invoke(Request $request): View
    {
        return view('finance.dashboard', [
            'widgets' => [
                ['label' => 'Cash in Hand', 'value' => '—'],
                ['label' => 'Bank Balance', 'value' => '—'],
                ['label' => "Today's Collection", 'value' => '—'],
                ['label' => "Today's Expense", 'value' => '—'],
                ['label' => 'Pending Approvals', 'value' => '—'],
            ],
        ]);
    }
}
