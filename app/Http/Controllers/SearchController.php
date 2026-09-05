<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CustomerIntakeSearchService;
use App\Services\GlobalSearch\ServiceCaseGlobalSearchProvider;
use App\Services\GlobalSearchService;
use App\Services\HistoricalInvoice\HistoricalCustomer360Resolver;
use App\Services\HistoricalInvoice\HistoricalInvoiceLookupService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $globalSearchService,
        private readonly CustomerIntakeSearchService $customerIntakeSearchService,
        private readonly HistoricalCustomer360Resolver $historicalCustomer360Resolver,
        private readonly ServiceCaseGlobalSearchProvider $serviceCaseGlobalSearchProvider,
    ) {}

    public function search(Request $request): JsonResponse|RedirectResponse
    {
        $query = $request->string('q')->trim()->toString();

        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonResponse($request, $query);
        }

        if ($query === '') {
            return redirect()->route('dashboard');
        }

        $user = $request->user();

        if (
            $user instanceof User
            && $user->can('incidents.view')
            && HistoricalInvoiceLookupService::looksLikeHistoricalInvoiceNumber($query)
            && $this->historicalCustomer360Resolver->resolve($query, $user) !== null
        ) {
            return redirect()->route('dashboard', ['q' => $query]);
        }

        if (
            FinanceAccess::allowsInvoices($user)
            && HistoricalInvoiceLookupService::looksLikeHistoricalInvoiceNumber($query)
        ) {
            return redirect()->route('finance.invoices.historical', [
                'q' => HistoricalInvoiceLookupService::normalizeLookupQuery($query),
            ]);
        }

        return redirect()->route('dashboard', ['q' => $query]);
    }

    private function jsonResponse(Request $request, string $query): JsonResponse
    {
        $user = $request->user();

        if ($query === '') {
            return response()->json([
                'match_count' => 0,
                'results' => [],
                'incident_ids' => [],
            ]);
        }

        if (! $user->can('incidents.view')) {
            return response()->json([
                'match_count' => 0,
                'results' => [],
                'incident_ids' => [],
            ]);
        }

        $results = $this->globalSearchService->search($user, $query);
        $payload = $results->map(fn ($result) => $result->toArray())->values();

        if ($payload->isEmpty() && HistoricalInvoiceLookupService::shouldOfferHistoricalLookup($query)) {
            $historicalMatch = $this->historicalCustomer360Resolver->resolve($query, $user);
            if ($historicalMatch !== null) {
                $payload = collect([
                    $this->serviceCaseGlobalSearchProvider->resultFor(
                        $historicalMatch['incident'],
                        $user,
                        $historicalMatch['invoice_number'],
                    )->toArray(),
                ]);
            }
        }

        $response = [
            'match_count' => $payload->count(),
            'results' => $payload,
            'incident_ids' => $payload
                ->where('type', 'service_case')
                ->pluck('incident_id')
                ->values(),
        ];

        if (
            $payload->isEmpty()
            && FinanceAccess::allowsInvoices($user)
            && HistoricalInvoiceLookupService::shouldOfferHistoricalLookup($query)
        ) {
            $normalized = HistoricalInvoiceLookupService::normalizeLookupQuery($query);
            $response['historical'] = [
                'query' => $normalized,
                'url' => route('finance.invoices.historical', ['q' => $normalized]),
                'kind' => HistoricalInvoiceLookupService::looksLikeHistoricalInvoiceNumber($normalized)
                    ? 'invoice'
                    : 'order',
            ];
        }

        if (
            $payload->isEmpty()
            && $this->canRunIntakeFallback($user)
            && ! HistoricalInvoiceLookupService::looksLikeHistoricalInvoiceNumber($query)
        ) {
            $parsedQuery = $this->customerIntakeSearchService->parseQuery($query);
            $intakeResult = $this->customerIntakeSearchService->search(
                phone: $parsedQuery['phone'],
                orderId: $parsedQuery['order_id'],
                serialNumber: $parsedQuery['serial_number'],
                user: $user,
            );

            $response['intake'] = $this->customerIntakeSearchService->toSearchPayload(
                $intakeResult,
                $parsedQuery,
            );
        }

        return response()->json($response);
    }

    private function canRunIntakeFallback(User $user): bool
    {
        return $user->can('orders.view') && $user->can('incidents.create');
    }
}
