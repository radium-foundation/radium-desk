<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\VerifyInventoryProductPackagingRequest;
use App\Models\InventoryProduct;
use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductPackagingController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(
                InventoryAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_INVENTORY_PACKAGING_VERIFY,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function edit(Request $request, InventoryProduct $product): View
    {
        $product->load('packaging.verifiedBy');

        return view('inventory.stock.packaging', [
            'product' => $product,
            'packaging' => $product->packaging,
            'filters' => $request->only(['branch_id', 'product_id']),
        ]);
    }

    public function update(VerifyInventoryProductPackagingRequest $request, InventoryProduct $product): RedirectResponse
    {
        $data = $request->validated();

        $product->packaging()->updateOrCreate(
            ['inventory_product_id' => $product->id],
            [
                'gross_weight' => $data['gross_weight'],
                'length' => $data['length'],
                'breadth' => $data['breadth'],
                'height' => $data['height'],
                'weight_unit' => $data['weight_unit'],
                'dimension_unit' => $data['dimension_unit'],
                'notes' => $data['notes'] ?? null,
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now(),
            ],
        );

        return redirect()
            ->route('inventory.stock.index', $request->only(['branch_id', 'product_id']))
            ->with('status', 'Packed shipping dimensions verified.');
    }
}
