<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventoryCustomer;
use App\Models\User;
use App\Services\Pos\PosCustomerLookupService;
use App\Support\Inventory\PosAccess;
use App\Support\ServicePos\ServiceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLookupController extends Controller
{
    public function __construct(
        private readonly PosCustomerLookupService $customerLookup,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($this->allowsCustomerLookup($request->user()), 403);

            return $next($request);
        });
    }

    public function lookup(Request $request): JsonResponse
    {
        return response()->json(
            $this->customerLookup->resolveByPhone($request->string('phone')->trim()->toString()),
        );
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json([
            'customers' => $this->customerLookup->search($request->string('q')->trim()->toString()),
        ]);
    }

    public function show(Request $request, InventoryCustomer $customer): JsonResponse
    {
        return response()->json($this->customerLookup->resolveById($customer->id));
    }

    private function allowsCustomerLookup(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_SELL)
            || ServiceAccess::allowsSell($user);
    }
}
