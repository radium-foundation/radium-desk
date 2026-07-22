<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRealtimeConnectionStatusRequest;
use App\Services\Realtime\RealtimeConnectionStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RealtimeConnectionStatusController extends Controller
{
    public function __invoke(
        UpdateRealtimeConnectionStatusRequest $request,
        RealtimeConnectionStatusService $connectionStatus,
    ): JsonResponse {
        $user = $request->user();
        $provider = (string) $request->validated('provider');
        $status = (string) $request->validated('status');
        $message = $request->validated('message');
        $previous = $connectionStatus->snapshot();

        match ($status) {
            'connected' => $connectionStatus->recordConnected($user, $provider),
            'connecting' => $connectionStatus->recordConnecting($user, $provider),
            'polling' => $connectionStatus->recordPolling($user, $provider, is_string($message) ? $message : null),
            'offline' => $connectionStatus->recordOffline($user, $provider),
            'error' => $connectionStatus->recordError($user, $provider, (string) ($message ?? 'Connection error')),
            default => $connectionStatus->recordDisconnected($user, $provider, is_string($message) ? $message : null),
        };

        $this->logStatusChange($status, $provider, $message, $previous);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    private function logStatusChange(string $status, string $provider, mixed $message, array $previous): void
    {
        if (($previous['provider'] ?? null) !== null
            && ($previous['provider'] ?? null) !== $provider
            && in_array($status, ['connected', 'connecting', 'polling'], true)) {
            Log::info('realtime.provider_change', [
                'from' => $previous['provider'],
                'to' => $provider,
            ]);
        }

        $logEvent = match ($status) {
            'connected' => 'realtime.connection_established',
            'polling' => is_string($message) && str_contains($message, 'fallback') ? 'realtime.fallback_activated' : null,
            'error', 'disconnected' => 'realtime.disconnect',
            'offline' => 'realtime.offline',
            default => null,
        };

        if ($logEvent === null) {
            return;
        }

        $context = [
            'provider' => $provider,
            'status' => $status,
        ];

        if (is_string($message) && $message !== '') {
            $context['reason'] = $message;
        }

        if ($logEvent === 'realtime.connection_established' && ($previous['status'] ?? null) !== 'connected') {
            if (in_array($previous['status'] ?? null, ['disconnected', 'offline', 'error', 'polling'], true)) {
                Log::info('realtime.reconnect_success', $context);
            }

            Log::info($logEvent, $context);

            return;
        }

        Log::info($logEvent, $context);
    }
}
