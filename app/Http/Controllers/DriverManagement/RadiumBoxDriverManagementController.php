<?php

namespace App\Http\Controllers\DriverManagement;

use App\Http\Controllers\Controller;
use App\Services\RadiumBox\RadiumBoxDriverUploadClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
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
                'mediaUploadEnabled' => false,
                'maxRawBytes' => 0,
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
            'mediaUploadEnabled' => $this->client->mediaUploadEnabled(),
            'maxRawBytes' => (int) config('driver_management.radiumbox_com.max_raw_bytes', 99_000_000),
            'drivers' => $drivers,
            'selected' => $selected,
            'pending' => session('pending'),
        ]);
    }

    public function authorizeMedia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_driver_id' => ['required', 'integer', 'min:1'],
        ]);

        if (! $this->client->mediaUploadEnabled()) {
            return response()->json(['message' => 'Media upload is not enabled.'], 503);
        }

        $result = $this->client->authorizeMediaUpload(
            $request->user(),
            (int) $validated['product_driver_id'],
        );

        return response()->json($result);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->client->mediaUploadEnabled()) {
            return back()->withErrors([
                'file' => 'Use the on-page upload control to send the file directly to the media host.',
            ]);
        }

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

    public function completeMediaQuarantine(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_driver_id' => ['required', 'integer', 'min:1'],
            'upload_id' => ['required', 'uuid'],
            'sha256' => ['required', 'string', 'size:64'],
            'bytes' => ['nullable', 'integer', 'min:0'],
        ]);

        return redirect()
            ->route('driver-management.radiumbox.index', ['product_driver_id' => $validated['product_driver_id']])
            ->with('success', 'File quarantined on the media host. Review checksum and publish when ready.')
            ->with('pending', [
                'upload_id' => $validated['upload_id'],
                'sha256' => strtolower($validated['sha256']),
                'bytes' => $validated['bytes'] ?? null,
            ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'product_driver_id' => ['required', 'integer', 'min:1'],
            'sha256' => ['nullable', 'string', 'size:64'],
        ]);

        $idempotencyKey = (string) $request->input('idempotency_key', '');
        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        $sha256 = isset($validated['sha256']) ? strtolower((string) $validated['sha256']) : null;

        $this->client->publish(
            $request->user(),
            $validated['upload_id'],
            (int) $validated['product_driver_id'],
            $idempotencyKey,
            $sha256,
        );

        $message = $this->client->mediaUploadEnabled()
            ? 'Published to the media driver download path. This does not prove OEM authenticity.'
            : 'Published to RadiumBox public download path. This does not prove OEM authenticity.';

        return redirect()
            ->route('driver-management.radiumbox.index', ['product_driver_id' => $validated['product_driver_id']])
            ->with('success', $message);
    }
}
