<?php

namespace App\Services\Purchasing;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceService
{
    public function __construct(
        private readonly PurchasingAuditService $audit,
        private readonly PurchasingDocumentService $documents,
        private readonly PurchasePaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(
        PurchaseOrder $po,
        array $data,
        User $actor,
        ?GoodsReceipt $receipt = null,
        ?UploadedFile $document = null,
    ): SupplierInvoice {
        return DB::transaction(function () use ($po, $data, $actor, $receipt, $document): SupplierInvoice {
            if ($receipt !== null && $receipt->purchase_order_id !== $po->id) {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => 'Receipt does not belong to this purchase order.',
                ]);
            }

            $vendor = Vendor::query()->findOrFail((int) ($data['vendor_id'] ?? $po->vendor_id));
            $invoiceNumber = trim((string) $data['supplier_invoice_number']);

            if (SupplierInvoice::query()->where('vendor_id', $vendor->id)->where('supplier_invoice_number', $invoiceNumber)->exists()) {
                throw ValidationException::withMessages([
                    'supplier_invoice_number' => 'This supplier invoice number already exists for the vendor.',
                ]);
            }

            $invoice = SupplierInvoice::query()->create([
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $po->id,
                'goods_receipt_id' => $receipt?->id,
                'supplier_invoice_number' => $invoiceNumber,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'invoice_amount' => $data['invoice_amount'],
                'taxable_amount' => $data['taxable_amount'] ?? null,
                'cgst_amount' => $data['cgst_amount'] ?? null,
                'sgst_amount' => $data['sgst_amount'] ?? null,
                'igst_amount' => $data['igst_amount'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'recorded_by_user_id' => $actor->id,
            ]);

            $this->payments->refreshPaymentStatus($invoice);
            $this->audit->log($actor, 'supplier_invoice.created', $invoice, null, $invoice->toArray());

            if ($document !== null) {
                $this->documents->attachSupplierInvoice($invoice, $document, $actor);
            }

            return $invoice->fresh();
        });
    }
}
