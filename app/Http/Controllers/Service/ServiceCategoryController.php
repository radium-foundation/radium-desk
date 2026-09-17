<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Support\ServicePos\ServiceAccess;
use Illuminate\View\View;

class ServiceCategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(ServiceAccess::allowsView($request->user()), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('services.categories.index', [
            'categories' => ServiceCategory::query()
                ->withCount('items')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
