<?php

namespace App\Http\Controllers\Pos;

use App\Enums\InventorySerialStatus;
use App\Http\Controllers\Controller;
use App\Models\FinancePaymentMethod;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryStockBalance;
use App\Services\Inventory\PosSaleService;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\PosAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CounterController extends Controller
{
    public function __construct(
        private readonly PosSaleService $sales,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PosAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function create(Request $request): View
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL),
            403,
        );

        $user = $request->user();
        $branches = InventoryBranchScope::allowedBranches($user);
        $operatingBranch = $this->resolveOperatingBranch($request, $branches);

        return view('pos.counter.create', [
            'branches' => $branches,
            'operatingBranch' => $operatingBranch,
            'paymentMethods' => $this->paymentMethods(),
            'needsBranchAssignment' => InventoryBranchScope::needsAssignment($user),
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
            'searchProductsUrl' => route('pos.products.search'),
            'searchSerialsUrl' => route('pos.serials.search'),
            'lookupCustomerUrl' => route('pos.customers.lookup'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL),
            403,
        );

        $data = $request->validate([
            'branch_id' => ['required', 'exists:inventory_branches,id'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'payment_method' => ['required', 'string', 'max:64'],
            'payment_reference' => ['nullable', 'string', 'max:128'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:inventory_products,id'],
            'lines.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.serials' => ['nullable', 'string'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $branch = InventoryBranchScope::requireBranchId($data['branch_id'], $request->user());
        $request->session()->put('pos.operating_branch_id', $branch->id);

        $lines = [];
        foreach ($data['lines'] as $line) {
            if (empty($line['product_id'])) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'variant_id' => ! empty($line['variant_id']) ? (int) $line['variant_id'] : null,
                'qty' => (int) $line['qty'],
                'serials' => $line['serials'] ?? '',
                'unit_price' => $line['unit_price'] ?? null,
                'discount' => $line['discount'] ?? 0,
            ];
        }

        $sale = $this->sales->completeSale(
            branch: $branch,
            customer: [
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'email' => $data['customer_email'] ?? null,
            ],
            lines: $lines,
            paymentMethod: $data['payment_method'],
            actor: $request->user(),
            headerDiscount: (float) ($data['discount'] ?? 0),
            paymentReference: $data['payment_reference'] ?? null,
            notes: $data['notes'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return redirect()->route('pos.sales.show', $sale)->with('status', 'Sale '.$sale->sale_no.' completed.');
    }

    public function searchProducts(Request $request): JsonResponse
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL),
            403,
        );

        $branch = InventoryBranchScope::requireBranchId($request->input('branch_id'), $request->user());
        $q = $request->string('q')->trim()->toString();
        if (strlen($q) < 1) {
            return response()->json(['products' => []]);
        }

        $products = InventoryProduct::query()
            ->with(['variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('name')])
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('sku', 'like', '%'.$q.'%')
                    ->orWhere('name', 'like', '%'.$q.'%');
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        $productIds = $products->pluck('id')->all();
        $balances = InventoryStockBalance::query()
            ->where('branch_id', $branch->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        return response()->json([
            'products' => $products->map(function (InventoryProduct $product) use ($balances): array {
                $rows = $balances->get($product->id) ?? collect();
                $rows = $rows instanceof Collection ? $rows : collect($rows);
                $variantRows = $rows->keyBy(fn ($row) => (int) ($row->variant_id ?? 0));

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'is_serialized' => $product->is_serialized,
                    'gst_percentage' => (float) $product->gst_percentage,
                    'unit_price' => (float) $product->unit_price,
                    'available_qty' => (int) $rows->sum('available_qty'),
                    'variants' => $product->variants->map(function ($variant) use ($product, $variantRows): array {
                        $balance = $variantRows->get($variant->id);

                        return [
                            'id' => $variant->id,
                            'sku' => $variant->sku,
                            'name' => $variant->name,
                            'unit_price' => (float) $product->priceFor($variant),
                            'available_qty' => (int) ($balance?->available_qty ?? 0),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ]);
    }

    public function searchSerials(Request $request): JsonResponse
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL),
            403,
        );

        $branch = InventoryBranchScope::requireBranchId($request->input('branch_id'), $request->user());
        $q = $request->string('q')->trim()->toString();
        $productId = $request->integer('product_id');
        $variantId = $request->integer('variant_id');

        $serials = InventorySerial::query()
            ->with(['product', 'variant'])
            ->where('branch_id', $branch->id)
            ->where('status', InventorySerialStatus::Available)
            ->when($productId > 0, fn ($query) => $query->where('product_id', $productId))
            ->when($variantId > 0, fn ($query) => $query->where('variant_id', $variantId))
            ->when($q !== '', fn ($query) => $query->where('serial_number', 'like', '%'.$q.'%'))
            ->orderBy('serial_number')
            ->limit(20)
            ->get();

        return response()->json([
            'serials' => $serials->map(fn (InventorySerial $serial): array => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'product_id' => $serial->product_id,
                'variant_id' => $serial->variant_id,
                'sku' => $serial->product?->sku,
                'product_name' => $serial->product?->name,
            ])->values()->all(),
        ]);
    }

    public function lookupCustomer(Request $request): JsonResponse
    {
        abort_unless(
            PosAccess::allowsPermission($request->user(), RolePermissionSeeder::PERMISSION_POS_SELL),
            403,
        );

        $phone = preg_replace('/\s+/', '', $request->string('phone')->trim()->toString()) ?? '';
        if ($phone === '') {
            return response()->json(['found' => false]);
        }

        $customer = InventoryCustomer::query()->where('phone', $phone)->first();
        if ($customer === null) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
        ]);
    }

    /**
     * @param  Collection<int, InventoryBranch>  $branches
     */
    private function resolveOperatingBranch(Request $request, $branches): ?InventoryBranch
    {
        $user = $request->user();

        if ($request->filled('branch_id')) {
            $branch = InventoryBranchScope::requireBranchId($request->input('branch_id'), $user);
            $request->session()->put('pos.operating_branch_id', $branch->id);

            return $branch;
        }

        $sessionId = $request->session()->get('pos.operating_branch_id');
        if ($sessionId) {
            try {
                return InventoryBranchScope::requireBranchId($sessionId, $user);
            } catch (\Throwable) {
                $request->session()->forget('pos.operating_branch_id');
            }
        }

        if ($branches->count() === 1) {
            $branch = $branches->first();
            $request->session()->put('pos.operating_branch_id', $branch->id);

            return $branch;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function paymentMethods(): array
    {
        $methods = FinancePaymentMethod::query()->where('is_active', true)->ordered()->pluck('name')->all();
        if ($methods !== []) {
            return $methods;
        }

        return ['Cash', 'UPI', 'Card', 'Bank Transfer', 'Other'];
    }
}
