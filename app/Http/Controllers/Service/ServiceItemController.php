<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Support\ServicePos\ServiceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceItemController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(ServiceAccess::allowsView($request->user()), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->trim()->toString();
        $categoryId = $request->integer('category_id');

        $items = ServiceItem::query()
            ->with('category')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->when($categoryId > 0, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('services.items.index', [
            'items' => $items,
            'categories' => ServiceCategory::query()->orderBy('sort_order')->orderBy('name')->get(),
            'filters' => $request->only(['q', 'category_id']),
            'canManage' => ServiceAccess::allowsManage($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless(ServiceAccess::allowsManage($request->user()), 403);

        return view('services.items.create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(ServiceAccess::allowsManage($request->user()), 403);

        $item = ServiceItem::query()->create($this->validated($request));

        return redirect()->route('services.items.edit', $item)->with('status', 'Service item created.');
    }

    public function edit(Request $request, ServiceItem $item): View
    {
        abort_unless(ServiceAccess::allowsManage($request->user()), 403);

        return view('services.items.edit', array_merge($this->formOptions(), [
            'item' => $item,
        ]));
    }

    public function update(Request $request, ServiceItem $item): RedirectResponse
    {
        abort_unless(ServiceAccess::allowsManage($request->user()), 403);

        $item->update($this->validated($request, $item->id));

        return redirect()->route('services.items.edit', $item)->with('status', 'Service item updated.');
    }

    public function toggle(Request $request, ServiceItem $item): RedirectResponse
    {
        abort_unless(ServiceAccess::allowsManage($request->user()), 403);

        $item->update(['is_active' => ! $item->is_active]);

        return back()->with('status', $item->is_active ? 'Service activated.' : 'Service deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'categories' => ServiceCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $uniqueCode = Rule::unique('service_items', 'code');
        if ($ignoreId !== null) {
            $uniqueCode = $uniqueCode->ignore($ignoreId);
        }

        $data = $request->validate([
            'category_id' => ['required', 'exists:service_categories,id'],
            'code' => ['nullable', 'string', 'max:64', $uniqueCode],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_label' => ['nullable', 'string', 'max:120'],
            'sac_code' => ['nullable', 'string', 'max:16', 'regex:/^\d{6}$/'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'price_ex_gst' => ['required', 'numeric', 'min:0'],
            'price_incl_gst' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        if (! empty($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return $data;
    }
}
