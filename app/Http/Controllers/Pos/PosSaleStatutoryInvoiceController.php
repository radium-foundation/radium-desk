<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Services\Pos\PosSaleStatutoryInvoiceShareService;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\PosAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PosSaleStatutoryInvoiceController extends Controller
{
    public function __construct(
        private readonly PosSaleStatutoryInvoiceShareService $share,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(PosAccess::allows($request->user()), 403);

            return $next($request);
        });
    }

    public function pdf(Request $request, InventorySale $sale): Response
    {
        $invoice = $this->authorizedInvoice($request, $sale);

        return $this->pdfResponse($invoice, inline: true);
    }

    public function download(Request $request, InventorySale $sale): Response
    {
        $invoice = $this->authorizedInvoice($request, $sale);

        return $this->pdfResponse($invoice, inline: false);
    }

    public function email(Request $request, InventorySale $sale): JsonResponse
    {
        $invoice = $this->authorizedInvoice($request, $sale);
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        try {
            $result = $this->share->email(
                $sale,
                $invoice,
                $request->user(),
                $data['email'] ?? null,
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to email the invoice.',
            ], 422);
        }

        return response()->json($result);
    }

    private function authorizedInvoice(Request $request, InventorySale $sale): StatutoryInvoice
    {
        $sale->loadMissing(['branch', 'statutoryInvoice']);
        InventoryBranchScope::assertCanOperate($request->user(), $sale->branch);
        $invoice = $sale->statutoryInvoice;
        abort_if($invoice === null, 404);

        return $invoice;
    }

    private function pdfResponse(StatutoryInvoice $invoice, bool $inline): Response
    {
        $binary = $this->share->pdfBinary($invoice);
        $disposition = $inline ? 'inline' : 'attachment';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$invoice->invoice_number.'.pdf"',
        ]);
    }
}
