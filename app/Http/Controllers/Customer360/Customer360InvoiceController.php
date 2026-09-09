<?php

namespace App\Http\Controllers\Customer360;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\StatutoryInvoiceForIncidentResolver;
use App\Services\StatutoryInvoice\StatutoryInvoiceShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class Customer360InvoiceController extends Controller
{
    public function __construct(
        private readonly StatutoryInvoiceForIncidentResolver $resolver,
        private readonly StatutoryInvoiceShareService $share,
    ) {}

    public function pdf(Request $request, Incident $incident, StatutoryInvoice $invoice): Response
    {
        $this->authorizeInvoice($request, $incident, $invoice);

        return $this->pdfResponse($invoice, inline: true);
    }

    public function download(Request $request, Incident $incident, StatutoryInvoice $invoice): Response
    {
        $this->authorizeInvoice($request, $incident, $invoice);

        return $this->pdfResponse($invoice, inline: false);
    }

    public function email(Request $request, Incident $incident, StatutoryInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $incident, $invoice);

        try {
            $result = $this->share->email($incident, $invoice, $request->user());
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to email the invoice.',
            ], 422);
        }

        return response()->json($result);
    }

    public function whatsapp(Request $request, Incident $incident, StatutoryInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $incident, $invoice);

        try {
            $result = $this->share->whatsapp($incident, $invoice, $request->user());
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to share the invoice on WhatsApp.',
            ], 422);
        }

        return response()->json($result);
    }

    private function authorizeInvoice(Request $request, Incident $incident, StatutoryInvoice $invoice): void
    {
        $this->authorize('view', $incident);
        $this->resolver->findAuthorized($incident, $invoice, $request->user());
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
