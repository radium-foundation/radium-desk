<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\FinancePaymentMethod;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Services\Inventory\PosSaleService;
use App\Support\Inventory\PosAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function create(): View
    {
        abort_unless(
            PosAccess::allowsPermission(request()->user(), RolePermissionSeeder::PERMISSION_POS_SELL),
            403,
        );

        return view('pos.counter.create', [
            'branches' => InventoryBranch::query()->where('is_active', true)->orderBy('name')->get(),
            'products' => InventoryProduct::query()->with('variants')->where('is_active', true)->orderBy('name')->get(),
            'paymentMethods' => $this->paymentMethods(),
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:inventory_products,id'],
            'lines.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.serials' => ['nullable', 'string'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

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
            branch: InventoryBranch::query()->findOrFail($data['branch_id']),
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
        );

        return redirect()->route('pos.sales.show', $sale)->with('status', 'Sale '.$sale->sale_no.' completed.');
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
