<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmLegacyVerificationRequest;
use App\Models\Order;
use App\Services\CustomerVerificationService;
use Illuminate\Http\JsonResponse;

class OrderLegacyVerificationController extends Controller
{
    public function __construct(
        private readonly CustomerVerificationService $customerVerificationService,
    ) {}

    public function store(ConfirmLegacyVerificationRequest $request, Order $order): JsonResponse
    {
        $this->customerVerificationService->confirmLegacyVerification(
            order: $order,
            actor: $request->user(),
            request: $request,
        );

        return response()->json([
            'message' => 'Legacy customer verification confirmed.',
            'verified' => true,
        ]);
    }
}
