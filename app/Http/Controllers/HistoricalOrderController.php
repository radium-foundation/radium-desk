<?php

namespace App\Http\Controllers;

use App\Services\HistoricalSearch\HistoricalOrderSummaryService;
use App\Services\HistoricalSearch\HistoricalSearchCircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoricalOrderController extends Controller
{
    public function __construct(
        private readonly HistoricalOrderSummaryService $summaryService,
        private readonly HistoricalSearchCircuitBreaker $circuitBreaker,
    ) {}

    public function show(Request $request, int $histOrder): JsonResponse
    {
        if (! config('historical_search.enabled') || $this->circuitBreaker->isOpen()) {
            return response()->json(['message' => 'Historical search is unavailable.'], 503);
        }

        $summary = $this->summaryService->forOrderId($histOrder);

        if ($summary === null) {
            return response()->json(['message' => 'Historical order not found.'], 404);
        }

        return response()->json([
            'summary' => $summary->toArray(),
        ]);
    }

    public function showByDocument(Request $request): JsonResponse
    {
        if (! config('historical_search.enabled') || $this->circuitBreaker->isOpen()) {
            return response()->json(['message' => 'Historical search is unavailable.'], 503);
        }

        $documentType = $request->string('document_type')->trim()->toString();
        $entityId = $request->integer('entity_id');

        if ($documentType === '' || $entityId <= 0) {
            return response()->json(['message' => 'Invalid historical document reference.'], 422);
        }

        $summary = $this->summaryService->forDocument($documentType, $entityId);

        if ($summary === null) {
            return response()->json(['message' => 'Historical order not found.'], 404);
        }

        return response()->json([
            'summary' => $summary->toArray(),
        ]);
    }
}
