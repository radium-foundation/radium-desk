<?php

namespace App\Http\Controllers\ServicePos;

use App\Http\Controllers\Controller;
use App\Models\ServiceQuote;
use App\Services\ServicePos\ServiceQuoteService;
use App\Support\ServicePos\ServiceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class QuoteController extends Controller
{
    public function __construct(
        private readonly ServiceQuoteService $quotes,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(ServiceAccess::allowsSell($request->user()), 403);

            return $next($request);
        });
    }

    public function show(ServiceQuote $quote): View
    {
        $quote->load(['lines', 'customer', 'branch', 'convertedServiceOrder']);

        return view('service-pos.quotes.show', [
            'quote' => $quote,
            'canConvert' => $quote->status->value !== 'converted' && $quote->status->value !== 'cancelled',
            'canIssueInvoice' => false,
        ]);
    }

    public function print(ServiceQuote $quote): View
    {
        $quote->load(['lines', 'customer', 'branch']);

        return view('service-pos.quotes.print', [
            'quote' => $quote,
        ]);
    }

    public function convert(ServiceQuote $quote): RedirectResponse
    {
        $order = $this->quotes->convertToOrder($quote, request()->user());

        return redirect()
            ->route('service-pos.orders.show', $order)
            ->with('status', 'Converted to service order '.$order->order_number.'.');
    }
}
