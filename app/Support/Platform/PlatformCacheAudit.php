<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Temporary P0 investigation auditor for Platform snapshot cache corruption.
 *
 * Enable with PLATFORM_CACHE_AUDIT=true (or config platform.cache_audit).
 * Writes JSON lines to storage/logs/platform-cache-audit.log
 *
 * Do not use as a permanent control plane — investigation only.
 */
final class PlatformCacheAudit
{
    public const LOG_FILE = 'platform-cache-audit.log';

    private static ?string $requestId = null;

    private static ?int $boundRequestObjectId = null;

    public static function enabled(): bool
    {
        return (bool) config('platform.cache_audit', env('PLATFORM_CACHE_AUDIT', false));
    }

    public static function requestId(): string
    {
        $request = request();
        $objectId = $request !== null ? spl_object_id($request) : null;

        if (
            self::$requestId === null
            || self::$boundRequestObjectId !== $objectId
        ) {
            self::$boundRequestObjectId = $objectId;
            self::$requestId = (string) ($request?->headers->get('X-Request-Id') ?: Str::uuid());
        }

        return self::$requestId;
    }

    public static function resetRequestId(?string $id = null): void
    {
        self::$requestId = $id;
        self::$boundRequestObjectId = null;
    }

    /**
     * @param  array<string, mixed>|null  $oldPayload
     * @param  array<string, mixed>|null  $newPayload
     */
    public static function write(
        string $service,
        string $method,
        string $cacheKey,
        ?array $oldPayload,
        ?array $newPayload,
        string $op = 'put',
    ): void {
        if (! self::enabled()) {
            return;
        }

        self::emit([
            'op' => $op,
            'direction' => 'write',
            'service' => $service,
            'method' => $method,
            'cache_key' => $cacheKey,
            'old_hash' => self::hashPayload($oldPayload),
            'new_hash' => self::hashPayload($newPayload),
            'old_status' => self::extractStatus($oldPayload),
            'new_status' => self::extractStatus($newPayload),
            'new_summary' => self::summarizePayload($newPayload),
            'stack' => self::stackFrames(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function read(
        string $service,
        string $method,
        string $cacheKey,
        ?array $payload,
        bool $hit,
    ): void {
        if (! self::enabled()) {
            return;
        }

        self::emit([
            'op' => 'get',
            'direction' => 'read',
            'service' => $service,
            'method' => $method,
            'cache_key' => $cacheKey,
            'hit' => $hit,
            'old_hash' => null,
            'new_hash' => self::hashPayload($payload),
            'old_status' => null,
            'new_status' => self::extractStatus($payload),
            'new_summary' => self::summarizePayload($payload),
            'stack' => self::stackFrames(limit: 8),
        ]);
    }

    public static function forget(string $service, string $method, string $cacheKey): void
    {
        if (! self::enabled()) {
            return;
        }

        self::emit([
            'op' => 'forget',
            'direction' => 'write',
            'service' => $service,
            'method' => $method,
            'cache_key' => $cacheKey,
            'old_hash' => null,
            'new_hash' => null,
            'old_status' => null,
            'new_status' => null,
            'new_summary' => null,
            'stack' => self::stackFrames(limit: 8),
        ]);
    }

    public static function clearLog(): void
    {
        $path = storage_path('logs/'.self::LOG_FILE);
        if (File::exists($path)) {
            File::put($path, '');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $path = storage_path('logs/'.self::LOG_FILE);
        if (! File::exists($path)) {
            return [];
        }

        $entries = [];
        foreach (preg_split("/\r\n|\n|\r/", trim((string) File::get($path))) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function emit(array $event): void
    {
        $request = request();
        $route = $request?->route();

        $payload = array_merge([
            'ts' => now()->format('Y-m-d H:i:s.u'),
            'request_id' => self::requestId(),
            'route' => $route?->getName() ?? $request?->path(),
            'uri' => $request?->method().' '.($request?->path() ?? 'cli'),
            'controller' => is_string($route?->getAction('controller') ?? null)
                ? (string) $route->getAction('controller')
                : null,
            'cli' => app()->runningInConsole(),
        ], $event);

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        File::append(storage_path('logs/'.self::LOG_FILE), $line.PHP_EOL);
        Log::channel('single')->debug('[platform-cache-audit] '.$line);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function hashPayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $normalized = $payload;
        unset($normalized['html'], $normalized['generated_at'], $normalized['updated_at'], $normalized['checked_at']);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function extractStatus(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (isset($payload['status']) && is_string($payload['status'])) {
            return $payload['status'];
        }

        if (isset($payload['overall_status']) && is_string($payload['overall_status'])) {
            return $payload['overall_status'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private static function summarizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $summary = [
            'status' => self::extractStatus($payload),
            'status_label' => $payload['status_label'] ?? $payload['overall_status_label'] ?? null,
            'available' => $payload['available'] ?? null,
            'stale' => $payload['stale'] ?? null,
        ];

        if (isset($payload['components']) && is_array($payload['components'])) {
            $components = [];
            foreach ($payload['components'] as $component) {
                if (! is_array($component) || ! isset($component['key'], $component['status'])) {
                    continue;
                }
                $components[(string) $component['key']] = (string) $component['status'];
            }
            $summary['components'] = $components;
        }

        if (isset($payload['summary']) && is_array($payload['summary'])) {
            $summary['zone_summary_state'] = $payload['summary']['state'] ?? null;
            $summary['alert_count'] = $payload['summary']['alert_count'] ?? null;
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private static function stackFrames(int $limit = 16): array
    {
        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 8) as $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $file = $frame['file'] ?? '';

            if (str_contains($class, 'PlatformCacheAudit') || str_contains($file, 'PlatformCacheAudit')) {
                continue;
            }

            if ($class === '' && $function === '') {
                continue;
            }

            $location = $file !== ''
                ? basename($file).':'.($frame['line'] ?? 0)
                : 'unknown';

            $frames[] = ($class !== '' ? $class.'::' : '').$function.' @ '.$location;

            if (count($frames) >= $limit) {
                break;
            }
        }

        return $frames;
    }
}
