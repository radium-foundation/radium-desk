<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\CustomerLedgerHistoryAuthorizationGate;
use App\CentralWallet\Application\LedgerEntryCursor;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Http\Resources\LedgerEntryResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class LedgerEntryController
{
    public function __construct(
        private readonly LedgerEntryReadService $ledgerReads,
        private readonly CustomerLedgerHistoryAuthorizationGate $customerHistoryAuthorization,
    ) {}

    public function show(Request $request, int $ledgerEntryId): JsonResponse
    {
        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $centralWalletId = $this->optionalCentralWalletId($request);

        if ($centralWalletId instanceof JsonResponse) {
            return $centralWalletId;
        }

        $entry = $this->ledgerReads->findForCaller($callerId, $ledgerEntryId, $centralWalletId);
        if ($entry === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(LedgerEntryResource::toArray($entry));
    }

    public function indexForWallet(Request $request, string $cwid): JsonResponse
    {
        try {
            Cwid::fromString($cwid);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $parameters = $this->parseListParameters($request, includeWalletFilters: true, includeSweepFilters: false);

        if ($parameters instanceof JsonResponse) {
            return $parameters;
        }

        try {
            $result = $this->ledgerReads->listForWallet($callerId, $cwid, $parameters);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'wallet_not_found') {
                return response()->json(['error' => 'not_found'], 404);
            }

            throw $exception;
        }

        return response()->json($result);
    }

    public function indexCustomerHistoryForWallet(Request $request, string $cwid): JsonResponse
    {
        try {
            Cwid::fromString($cwid);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $localUserId = trim((string) $request->query('local_user_id', ''));
        if ($localUserId === '') {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'local_user_id is required.',
            ], 422);
        }

        $authorizationError = $this->customerHistoryAuthorization->authorize($callerId, $cwid, $localUserId);
        if ($authorizationError !== null) {
            $status = $authorizationError === 'customer_history_read_disabled' ? 503 : 403;

            return response()->json(['error' => $authorizationError], $status);
        }

        $parameters = $this->parseListParameters($request, includeWalletFilters: true, includeSweepFilters: false);
        if ($parameters instanceof JsonResponse) {
            return $parameters;
        }

        try {
            $result = $this->ledgerReads->listCustomerHistoryForWallet($cwid, $parameters);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'customer_history_not_configured') {
                return response()->json(['error' => 'customer_history_read_disabled'], 503);
            }

            throw $exception;
        }

        return response()->json($result);
    }

    public function index(Request $request): JsonResponse
    {
        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $parameters = $this->parseListParameters($request, includeWalletFilters: true, includeSweepFilters: true);

        if ($parameters instanceof JsonResponse) {
            return $parameters;
        }

        $result = $this->ledgerReads->listForCaller($callerId, $parameters);

        return response()->json($result);
    }

    private function optionalCentralWalletId(Request $request): string|JsonResponse|null
    {
        if (! $request->has('central_wallet_id')) {
            return null;
        }

        $centralWalletId = trim((string) $request->query('central_wallet_id', ''));
        if ($centralWalletId === '') {
            return response()->json(['error' => 'validation_error', 'message' => 'central_wallet_id must be a valid UUID.'], 422);
        }

        try {
            Cwid::fromString($centralWalletId);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'validation_error', 'message' => 'central_wallet_id must be a valid UUID.'], 422);
        }

        return $centralWalletId;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function parseListParameters(
        Request $request,
        bool $includeWalletFilters,
        bool $includeSweepFilters,
    ): array|JsonResponse {
        if ($request->query->has('source_system')) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'source_system is derived from authentication and cannot be supplied as a query parameter.',
            ], 422);
        }

        $defaultLimit = max(1, (int) config('central_wallet.ledger_read.default_page_size', 100));
        $maxLimit = max($defaultLimit, (int) config('central_wallet.ledger_read.max_page_size', 500));
        $maxDateRangeDays = max(1, (int) config('central_wallet.ledger_read.max_date_range_days', 31));

        $limitInput = $request->query('limit');
        if ($limitInput === null) {
            $limit = $defaultLimit;
        } else {
            if (! is_numeric($limitInput) || (int) $limitInput != $limitInput) {
                return response()->json(['error' => 'validation_error', 'message' => 'limit must be a positive integer.'], 422);
            }

            $limit = (int) $limitInput;
            if ($limit < 1) {
                return response()->json(['error' => 'validation_error', 'message' => 'limit must be at least 1.'], 422);
            }

            if ($limit > $maxLimit) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => "limit must not exceed {$maxLimit}.",
                ], 422);
            }
        }

        $cursor = null;
        $cursorInput = trim((string) $request->query('cursor', ''));
        if ($cursorInput !== '') {
            try {
                $cursor = LedgerEntryCursor::decode($cursorInput);
            } catch (InvalidArgumentException) {
                return response()->json(['error' => 'validation_error', 'message' => 'cursor is invalid.'], 422);
            }
        }

        $postedFrom = $this->parsePostedBoundary($request, 'posted_from');
        if ($postedFrom instanceof JsonResponse) {
            return $postedFrom;
        }

        $postedTo = $this->parsePostedBoundary($request, 'posted_to');
        if ($postedTo instanceof JsonResponse) {
            return $postedTo;
        }

        if ($postedFrom !== null && $postedTo !== null && $postedFrom->greaterThanOrEqualTo($postedTo)) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'posted_from must be before posted_to.',
            ], 422);
        }

        if ($postedFrom !== null && $postedTo !== null) {
            $spanDays = $postedFrom->diffInDays($postedTo);
            if ($spanDays > $maxDateRangeDays) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => "posted_from and posted_to span must not exceed {$maxDateRangeDays} days.",
                ], 422);
            }
        }

        $statusExplicit = $request->query->has('status');
        $status = null;
        if ($statusExplicit) {
            $statusValue = (string) $request->query('status', '');
            if (! in_array($statusValue, array_column(LedgerEntryStatus::cases(), 'value'), true)) {
                return response()->json(['error' => 'validation_error', 'message' => 'status is invalid.'], 422);
            }
            $status = LedgerEntryStatus::from($statusValue);
        }

        $entryType = null;
        if ($request->query->has('entry_type')) {
            $entryTypeValue = (string) $request->query('entry_type', '');
            if (! in_array($entryTypeValue, array_column(LedgerEntryType::cases(), 'value'), true)) {
                return response()->json(['error' => 'validation_error', 'message' => 'entry_type is invalid.'], 422);
            }
            $entryType = LedgerEntryType::from($entryTypeValue);
        }

        $sourceReference = $this->optionalStringFilter($request, 'source_reference', 191);
        if ($sourceReference instanceof JsonResponse) {
            return $sourceReference;
        }

        $businessReference = $this->optionalStringFilter($request, 'business_reference', 191);
        if ($businessReference instanceof JsonResponse) {
            return $businessReference;
        }

        $correlationId = $this->optionalUuidFilter($request, 'correlation_id');
        if ($correlationId instanceof JsonResponse) {
            return $correlationId;
        }

        $centralWalletId = null;
        if ($includeSweepFilters && $request->query->has('central_wallet_id')) {
            $centralWalletId = trim((string) $request->query('central_wallet_id', ''));
            if ($centralWalletId === '') {
                return response()->json(['error' => 'validation_error', 'message' => 'central_wallet_id must be a valid UUID.'], 422);
            }

            try {
                Cwid::fromString($centralWalletId);
            } catch (InvalidArgumentException) {
                return response()->json(['error' => 'validation_error', 'message' => 'central_wallet_id must be a valid UUID.'], 422);
            }
        }

        $ledgerEntryId = null;
        if ($includeSweepFilters && $request->query->has('ledger_entry_id')) {
            $ledgerEntryIdInput = $request->query('ledger_entry_id');
            if (! is_numeric($ledgerEntryIdInput) || (int) $ledgerEntryIdInput < 1 || (int) $ledgerEntryIdInput != $ledgerEntryIdInput) {
                return response()->json(['error' => 'validation_error', 'message' => 'ledger_entry_id must be a positive integer.'], 422);
            }
            $ledgerEntryId = (int) $ledgerEntryIdInput;
        }

        if (! $includeWalletFilters) {
            return response()->json(['error' => 'validation_error'], 422);
        }

        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'source_reference' => $sourceReference,
            'business_reference' => $businessReference,
            'correlation_id' => $correlationId,
            'entry_type' => $entryType,
            'status' => $status,
            'status_explicit' => $statusExplicit,
            'posted_from' => $postedFrom,
            'posted_to' => $postedTo,
            'central_wallet_id' => $centralWalletId,
            'ledger_entry_id' => $ledgerEntryId,
        ];
    }

    private function parsePostedBoundary(Request $request, string $key): CarbonImmutable|JsonResponse|null
    {
        if (! $request->query->has($key)) {
            return null;
        }

        $value = trim((string) $request->query($key, ''));
        if ($value === '') {
            return response()->json(['error' => 'validation_error', 'message' => "{$key} must be a valid ISO8601 timestamp."], 422);
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return response()->json(['error' => 'validation_error', 'message' => "{$key} must be a valid ISO8601 timestamp."], 422);
        }
    }

    private function optionalStringFilter(Request $request, string $key, int $maxLength): string|JsonResponse|null
    {
        if (! $request->query->has($key)) {
            return null;
        }

        $value = (string) $request->query($key, '');
        if (strlen($value) > $maxLength) {
            return response()->json([
                'error' => 'validation_error',
                'message' => "{$key} must not exceed {$maxLength} characters.",
            ], 422);
        }

        return $value;
    }

    private function optionalUuidFilter(Request $request, string $key): string|JsonResponse|null
    {
        if (! $request->query->has($key)) {
            return null;
        }

        $value = trim((string) $request->query($key, ''));
        if ($value === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            return response()->json(['error' => 'validation_error', 'message' => "{$key} must be a valid UUID."], 422);
        }

        return strtolower($value);
    }
}
