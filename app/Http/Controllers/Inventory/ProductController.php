<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\DeviceModel;
use App\Models\InventoryProduct;
use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(
                InventoryAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();

        $products = InventoryProduct::query()
            ->with('deviceModel')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('sku', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('inventory.products.index', [
            'products' => $products,
            'filters' => $request->only(['q']),
        ]);
    }

    public function create(): View
    {
        return view('inventory.products.create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $product = InventoryProduct::query()->create($this->validated($request));
        $this->syncVariants($request, $product);

        return redirect()->route('inventory.products.edit', $product)->with('status', 'Product created.');
    }

    public function edit(InventoryProduct $product): View
    {
        $product->load('variants');

        return view('inventory.products.edit', array_merge($this->formOptions(), [
            'product' => $product,
        ]));
    }

    public function update(Request $request, InventoryProduct $product): RedirectResponse
    {
        $product->update($this->validated($request, $product->id));
        $this->syncVariants($request, $product);

        return redirect()->route('inventory.products.edit', $product)->with('status', 'Product updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'deviceModels' => DeviceModel::query()->orderBy('display_order')->orderBy('name')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:inventory_products,sku';
        if ($ignoreId !== null) {
            $unique .= ','.$ignoreId;
        }

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', $unique],
            'name' => ['required', 'string', 'max:160'],
            'hsn_code' => ['nullable', 'string', 'max:16'],
            'gst_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'device_model_id' => ['nullable', 'exists:device_models,id'],
            'is_serialized' => ['sometimes', 'boolean'],
            'tracks_batch' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['sku'] = strtoupper($data['sku']);
        $data['is_serialized'] = $request->boolean('is_serialized');
        $data['tracks_batch'] = $request->boolean('tracks_batch');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function syncVariants(Request $request, InventoryProduct $product): void
    {
        $rows = $request->input('variants', []);
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '' || $name === '') {
                continue;
            }

            $product->variants()->updateOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'unit_price' => $row['unit_price'] !== '' && $row['unit_price'] !== null
                        ? $row['unit_price']
                        : null,
                    'is_active' => ! empty($row['is_active']),
                ],
            );
        }
    }
}
