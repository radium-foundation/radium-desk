<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\InventoryOpeningImportStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryOpeningImportBatch;
use App\Services\Inventory\Opening\OpeningInventoryImportService;
use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OpeningImportController extends Controller
{
    public function __construct(
        private readonly OpeningInventoryImportService $imports,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                InventoryAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_INVENTORY_OPENING_IMPORT,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function create(): View
    {
        return view('inventory.opening-import.create', [
            'result' => null,
        ]);
    }

    public function preview(Request $request): View
    {
        $data = $request->validate([
            'workbook' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $file = $data['workbook'];
        $stored = $file->store('inventory-opening/imports', 'local');
        $path = Storage::disk('local')->path($stored);

        $result = $this->imports->preview(
            $path,
            $request->user(),
            $file->getClientOriginalName(),
            $stored,
        );

        return view('inventory.opening-import.create', [
            'result' => $result,
        ]);
    }

    public function apply(Request $request, InventoryOpeningImportBatch $batch): RedirectResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
        ]);

        if ($batch->stored_path === null || ! Storage::disk('local')->exists($batch->stored_path)) {
            throw ValidationException::withMessages([
                'workbook' => 'The previewed workbook is no longer on disk. Upload it again.',
            ]);
        }

        if ($batch->status === InventoryOpeningImportStatus::Applied) {
            return redirect()
                ->route('inventory.opening-import.create')
                ->with('status', 'That workbook was already applied. Stock was not changed again.');
        }

        $path = Storage::disk('local')->path($batch->stored_path);

        try {
            $result = $this->imports->apply(
                $path,
                $request->user(),
                $batch->source_filename,
                $batch->stored_path,
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('inventory.opening-import.create')
                ->withErrors($exception->errors());
        }

        $message = $result->alreadyApplied
            ? 'That workbook was already applied. Stock was not changed again.'
            : 'Opening inventory applied: '.$result->rowsApplied.' row(s), '.$result->skusCreated.' SKU(s) created.';

        return redirect()->route('inventory.stock.index')->with('status', $message);
    }
}
