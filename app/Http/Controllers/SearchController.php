<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function index(Request $request): View
    {
        $query = $request->string('q')->trim()->toString();

        $results = $this->searchService->search($request->user(), $query, [
            'orders' => $request->integer('orders_page', 1),
            'incidents' => $request->integer('incidents_page', 1),
            'approvals' => $request->integer('approvals_page', 1),
            'refunds' => $request->integer('refunds_page', 1),
        ]);

        return view('search.index', [
            'query' => $query,
            'results' => $results,
            'totalResults' => $this->searchService->totalResults($results),
        ]);
    }
}
