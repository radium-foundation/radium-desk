<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RadiumBoxReadLookupRequest;
use App\Services\RadiumBoxRead\RadiumBoxReadDisabledException;
use App\Services\RadiumBoxRead\RadiumBoxReadMisconfiguredException;
use App\Services\RadiumBoxRead\RadiumBoxReadService;
use App\Support\RadiumBoxRead\RadiumBoxReadAccess;
use Illuminate\Http\JsonResponse;

class RadiumBoxReadController extends Controller
{
    public function __construct(
        private readonly RadiumBoxReadService $readService,
    ) {}

    public function index(RadiumBoxReadLookupRequest $request): JsonResponse
    {
        $gate = $this->gate($request->user());
        if ($gate !== null) {
            return $gate;
        }

        try {
            $result = $this->readService->lookup(
                $request->identifierType(),
                $request->identifier(),
                $request->page(),
                $request->perPage(),
                $request->user(),
            );
        } catch (RadiumBoxReadDisabledException) {
            return $this->unavailable('radiumbox_read_disabled');
        } catch (RadiumBoxReadMisconfiguredException) {
            return $this->unavailable('radiumbox_read_misconfigured');
        }

        return response()->json($result->toArray());
    }

    public function show(int $commercialId): JsonResponse
    {
        $user = request()->user();
        $gate = $this->gate($user);
        if ($gate !== null) {
            return $gate;
        }

        if ($commercialId < 1) {
            return response()->json(['error' => 'invalid_commercial_id'], 422);
        }

        try {
            $record = $this->readService->findCommercial($commercialId, $user);
        } catch (RadiumBoxReadDisabledException) {
            return $this->unavailable('radiumbox_read_disabled');
        } catch (RadiumBoxReadMisconfiguredException) {
            return $this->unavailable('radiumbox_read_misconfigured');
        }

        if ($record === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => $record->toArray()]);
    }

    private function gate(mixed $user): ?JsonResponse
    {
        if (! RadiumBoxReadAccess::allows($user)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return null;
    }

    private function unavailable(string $code): JsonResponse
    {
        return response()->json(['error' => $code], 503);
    }
}
