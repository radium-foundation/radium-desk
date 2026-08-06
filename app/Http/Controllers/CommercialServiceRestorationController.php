<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommercialServiceRestorationRequest;
use App\Models\CommercialServiceRestoration;
use App\Models\Incident;
use App\Models\RefundRequest;
use App\Services\Commercial\CommercialServiceRestorationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommercialServiceRestorationController extends Controller
{
    public function __construct(
        private readonly CommercialServiceRestorationService $restorationService,
    ) {}

    public function store(
        StoreCommercialServiceRestorationRequest $request,
        Incident $incident,
        RefundRequest $refund,
    ): JsonResponse {
        $this->authorize('view', $incident);

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return response()->json([
                'success' => false,
                'message' => 'Service case is not linked to an order.',
            ], 422);
        }

        try {
            $restoration = $this->restorationService->restore(
                $order,
                $refund,
                $request->user(),
                [
                    'finance_verified' => $request->boolean('finance_verified'),
                    'wallet_reversed_externally' => $request->boolean('wallet_reversed_externally'),
                    'wallet_reversal_reference' => $request->string('wallet_reversal_reference')->toString(),
                    'finance_note' => $request->input('finance_note'),
                ],
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to restore commercial service.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Commercial service restored. Assign Reference is available again.',
            'restoration_id' => $restoration->id,
            'incident_id' => $incident->id,
        ]);
    }

    public function revoke(
        Request $request,
        Incident $incident,
        CommercialServiceRestoration $restoration,
    ): JsonResponse {
        $this->authorize('view', $incident);
        abort_unless(
            $request->user()?->can(RolePermissionSeeder::PERMISSION_COMMERCIAL_SERVICE_RESTORE),
            403,
        );

        $incident->loadMissing('order');

        if ($incident->order_id === null || (int) $restoration->order_id !== (int) $incident->order_id) {
            return response()->json([
                'success' => false,
                'message' => 'Restoration does not belong to this service case order.',
            ], 422);
        }

        try {
            $this->restorationService->revoke($restoration, $request->user());
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Unable to revoke restoration.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Commercial service restoration revoked. Commercial block restored.',
            'incident_id' => $incident->id,
        ]);
    }
}
