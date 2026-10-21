<?php

namespace App\Http\Controllers\DriverManagement;

use App\Http\Controllers\Controller;
use App\Services\RadiumBox\RadiumBoxDriverUploadClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RadiumBoxDriverManagementController extends Controller
{
    public function __construct(
        private readonly RadiumBoxDriverUploadClient $client,
    ) {
        $this->middleware('permission:'.RolePermissionSeeder::PERMISSION_DRIVERS_MANAGE_RADIUMBOX);
    }

    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->client->isConfigured()) {
            return view('driver-management.radiumbox.index', [
                'configured' => false,
                'drivers' => [],
                'selected' => null,
                'pending' => null,
            ]);
        }

        $catalog = $this->client->fetchCatalog();
        $drivers = collect($catalog['drivers'] ?? []);
        $selectedId = (int) $request->query('product_driver_id', 0);
        $selected = $drivers->firstWhere('id', $selectedId);

        return view('driver-management.radiumbox.index', [
            'configured' => true,
            'drivers' => $drivers,
            'selected' => $selected,
            'pending' => session('pending'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_driver_id' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        if ($file === null) {
            return back()->withErrors(['file' => 'A file is required.']);
        }

        $result = $this->client->quarantineUpload(
            $request->user(),
            (int) $validated['product_driver_id'],
            $file,
        );

        return redirect()
            ->route('driver-management.radiumbox.index', ['product_driver_id' => $validated['product_driver_id']])
            ->with('success', 'File quarantined on RadiumBox. Review checksum and publish when ready.')
            ->with('pending', [
                'upload_id' => $result['upload_id'] ?? null,
                'sha256' => $result['sha256'] ?? null,
                'bytes' => $result['bytes'] ?? null,
            ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'product_driver_id' => ['required', 'integer', 'min:1'],
        ]);

        $idempotencyKey = (string) $request->input('idempotency_key', '');
        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        $this->client->publish(
            $request->user(),
            $validated['upload_id'],
            (int) $validated['product_driver_id'],
            $idempotencyKey,
        );

        return redirect()
            ->route('driver-management.radiumbox.index', ['product_driver_id' => $validated['product_driver_id']])
            ->with('success', 'Published to RadiumBox public download path. This does not prove OEM authenticity.');
    }
}
