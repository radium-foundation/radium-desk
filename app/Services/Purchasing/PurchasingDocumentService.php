<?php

namespace App\Services\Purchasing;

use App\Models\PurchasingDocument;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PurchasingDocumentService
{
    public function __construct(
        private readonly PurchasingAuditService $audit,
    ) {}

    public function attachSupplierInvoice(SupplierInvoice $invoice, UploadedFile $file, User $actor): PurchasingDocument
    {
        return DB::transaction(function () use ($invoice, $file, $actor): PurchasingDocument {
            $directory = 'private/purchasing/supplier-invoices/'.$invoice->id;
            $stored = $file->store($directory, 'local');

            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([
                    'document' => 'The supplier invoice document could not be stored.',
                ]);
            }

            $document = PurchasingDocument::query()->create([
                'document_type' => 'supplier_invoice',
                'related_type' => SupplierInvoice::class,
                'related_id' => $invoice->id,
                'disk' => 'local',
                'path' => $stored,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize() ?: null,
                'uploaded_by_user_id' => $actor->id,
                'uploaded_at' => now(),
            ]);

            $this->audit->log($actor, 'supplier_invoice.document_uploaded', $invoice, null, [
                'document_id' => $document->id,
                'filename' => $document->original_filename,
            ]);

            return $document;
        });
    }

    public function downloadResponse(PurchasingDocument $document)
    {
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type ?? 'application/octet-stream'],
        );
    }
}
