<?php

namespace App\Services\OrderLookup;

use App\Models\Order;
use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;
use App\Services\RdService\RdServiceFetchResult;
use App\Services\RdService\RdServiceOrderId;
use App\Services\RdService\RdServiceOrderMapper;
use App\Support\BusinessOrderId;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpokeOrderClient
{
    public function __construct(
        private readonly RdServiceOrderMapper $mapper,
        private readonly string $source,
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    public function isConfigured(): bool
    {
        $config = $this->config();
        if (! (bool) ($config['enabled'] ?? false)) {
            return false;
        }

        if ($this->token() === '') {
            return false;
        }

        return $this->originBaseUrl() !== null;
    }

    public function isEligible(?string $orderId): bool
    {
        if (! $this->isConfigured() || ! is_string($orderId) || $orderId === '') {
            return false;
        }

        $parsed = BusinessOrderId::parse($orderId);
        $accepts = $this->config()['accepts'] ?? [];

        if ($parsed !== null) {
            $token = strtolower($parsed['prefix']);
            if (in_array($token, $accepts, true)) {
                if ($parsed['hardware'] && ! in_array($token, ['rde', 'rin', 'rbp', 'rdp'], true)) {
                    return false;
                }

                return true;
            }

            if ($parsed['prefix'] === 'RBX' && in_array('rbx', $accepts, true)) {
                return true;
            }

            return false;
        }

        if (Order::isInquiryOrderId($orderId)) {
            return false;
        }

        if (Order::isHardwareOrderId($orderId)) {
            $prefix = strtoupper(substr(trim($orderId), 0, 3));

            return ($prefix === 'RDE' && in_array('rde', $accepts, true))
                || ($prefix === 'RBP' && in_array('rbp', $accepts, true))
                || ($prefix === 'RIN' && in_array('rin', $accepts, true))
                || ($prefix === 'RDP' && in_array('rdp', $accepts, true));
        }

        return in_array('rd', $accepts, true) && RdServiceOrderId::isValid($orderId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchPayload(string $orderId): ?array
    {
        if (! $this->isConfigured() || ! $this->isEligible($orderId)) {
            return null;
        }

        $baseUrl = $this->originBaseUrl();
        if ($baseUrl === null) {
            return null;
        }

        $normalized = trim($orderId);

        try {
            $path = '/api/integrations/v1/rd-orders/'.rawurlencode($normalized);
            if (! $this->facadeIsFaked()) {
                $response = $this->curlGet(rtrim($baseUrl, '/').$path);
            } else {
                $request = Http::baseUrl($baseUrl)
                    ->acceptJson()
                    ->withToken($this->token())
                    ->connectTimeout((int) ($this->config()['connect_timeout_seconds'] ?? 3))
                    ->timeout((int) ($this->config()['timeout_seconds'] ?? 8));
                $host = $this->requestHostHeader();
                if ($host !== null) {
                    $request = $request->withHeaders(['Host' => $host]);
                }
                $response = $request->get($path);
            }
            $payload = $response->json();

            return is_array($payload) ? $payload : null;
        } catch (ConnectionException|RequestException) {
            return null;
        }
    }

    public function fetch(string $orderId): RdServiceFetchResult
    {
        if (! $this->isConfigured()) {
            return $this->skip('not_configured', 'Spoke '.$this->source.' is not configured.');
        }

        if (! $this->isEligible($orderId)) {
            return $this->skip('invalid_order_id', 'Order ID is not valid for '.$this->source.'.');
        }

        $baseUrl = $this->originBaseUrl();
        if ($baseUrl === null) {
            return $this->skip('insecure_base_url', 'Spoke base URL must be HTTPS or loopback HTTP with a Host.');
        }

        $normalized = trim($orderId);

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) ($this->config()['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($this->config()['timeout_seconds'] ?? 8));

            $host = $this->requestHostHeader();
            if ($host !== null) {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $this->dispatchGet($request, $baseUrl, '/api/integrations/v1/rd-orders/'.rawurlencode($normalized));

            return $this->interpretResponse($normalized, $response->status(), $response->json(), $response->header('Retry-After'));
        } catch (ConnectionException $exception) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: $this->redact($exception->getMessage()),
                errorType: 'connection_error',
            );
        } catch (RequestException $exception) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: $this->redact($exception->getMessage()),
                errorType: 'request_error',
            );
        } catch (RadiumBoxOrderNotFoundException $exception) {
            return $this->notFound($this->redact($exception->getMessage()));
        } catch (RadiumBoxInvalidResponseException $exception) {
            return $this->malformed($this->redact($exception->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchHistoricalInvoice(string $invoiceNumber): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $baseUrl = $this->originBaseUrl();
        $path = $this->config()['historical_invoice_path'] ?? null;
        if ($baseUrl === null || ! is_string($path) || $path === '') {
            return null;
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) ($this->config()['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($this->config()['timeout_seconds'] ?? 8));

            $host = $this->requestHostHeader();
            if ($host !== null) {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $this->dispatchGet($request, $baseUrl, rtrim($path, '/').'/'.rawurlencode($invoiceNumber));
            $payload = $response->json();

            return is_array($payload) ? $payload + ['_http_status' => $response->status()] : [
                '_http_status' => $response->status(),
            ];
        } catch (ConnectionException|RequestException) {
            return [
                '_http_status' => 0,
                'eligibility' => 'source_unavailable',
                'message' => 'Historical invoice source is temporarily unavailable.',
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchHistoricalInvoiceByOrderId(string $orderId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $baseUrl = $this->historicalIntegrationBaseUrl();
        $path = $this->config()['historical_invoice_path'] ?? null;
        if ($baseUrl === null || ! is_string($path) || $path === '') {
            return [
                '_http_status' => 0,
                'eligibility' => 'source_unavailable',
                'message' => 'Historical invoice source requires a configured HTTPS base URL.',
            ];
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withToken($this->token())
                ->connectTimeout((int) ($this->config()['connect_timeout_seconds'] ?? 3))
                ->timeout((int) ($this->config()['timeout_seconds'] ?? 8));

            $host = $this->requestHostHeader();
            if ($host !== null) {
                $request = $request->withHeaders(['Host' => $host]);
            }

            $response = $this->dispatchGet($request, $baseUrl, rtrim($path, '/'), [
                'orderid' => $orderId,
            ]);
            $payload = $response->json();

            return is_array($payload) ? $payload + ['_http_status' => $response->status()] : [
                '_http_status' => $response->status(),
            ];
        } catch (ConnectionException|RequestException) {
            return [
                '_http_status' => 0,
                'eligibility' => 'source_unavailable',
                'message' => 'Historical invoice source is temporarily unavailable.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function dispatchGet(PendingRequest $request, string $baseUrl, string $path, array $query = []): \Illuminate\Http\Client\Response
    {
        if ($this->facadeIsFaked()) {
            return $query === [] ? $request->get($path) : $request->get($path, $query);
        }

        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->curlGet($url);
    }

    private function facadeIsFaked(): bool
    {
        $factory = Http::getFacadeRoot();
        if (! $factory instanceof Factory) {
            return false;
        }
        if ($factory->preventingStrayRequests()) {
            return true;
        }
        $stubs = (new \ReflectionProperty($factory, 'stubCallbacks'))->getValue($factory);

        return $stubs instanceof Collection && $stubs->isNotEmpty();
    }

    /**
     * ext-curl honors the connect timeout inside curl_exec, then CurlHandle
     * destruction waits for the macOS resolver thread. A curl process exits
     * with that timeout, so the resolver cannot keep this request open.
     */
    private function curlGet(string $url): \Illuminate\Http\Client\Response
    {
        $binary = $this->curlBinary();
        if ($binary === null) {
            throw new ConnectionException('Spoke request transport is unavailable.');
        }

        $connectSeconds = max(0.001, (float) ($this->config()['connect_timeout_seconds'] ?? 3));
        $timeoutSeconds = max($connectSeconds, (float) ($this->config()['timeout_seconds'] ?? 8));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer '.$this->token(),
        ];
        $host = $this->requestHostHeader();
        if (is_string($host) && $host !== '') {
            $headers[] = 'Host: '.$host;
        }
        foreach ([$url, ...$headers] as $value) {
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new ConnectionException('Spoke request is invalid.');
            }
        }
        $headerFile = tempnam(sys_get_temp_dir(), 'spoke-hdr-');
        $bodyFile = tempnam(sys_get_temp_dir(), 'spoke-body-');
        if ($headerFile === false || $bodyFile === false) {
            if (is_string($headerFile)) {
                @unlink($headerFile);
            }
            if (is_string($bodyFile)) {
                @unlink($bodyFile);
            }
            throw new ConnectionException('Unable to prepare the spoke request.');
        }

        $config = "silent\nshow-error\nlocation\nmax-redirs = 5\n";
        $config .= 'connect-timeout = '.sprintf('%.3F', $connectSeconds)."\n";
        $config .= 'max-time = '.sprintf('%.3F', $timeoutSeconds)."\n";
        $config .= 'dump-header = '.$this->curlConfigQuote($headerFile)."\n";
        $config .= 'output = '.$this->curlConfigQuote($bodyFile)."\n";
        $config .= 'url = '.$this->curlConfigQuote($url)."\n";
        foreach ($headers as $header) {
            $config .= 'header = '.$this->curlConfigQuote($header)."\n";
        }

        $pipes = [];
        $process = proc_open(
            [$binary, '--config', '-', '--write-out', '%{http_code}'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            @unlink($headerFile);
            @unlink($bodyFile);
            throw new ConnectionException('Unable to start the spoke request.');
        }

        $stdout = '';
        $stderr = '';
        try {
            fwrite($pipes[0], $config);
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + $timeoutSeconds + 1.0;
            $timedOut = false;
            $exitCode = null;
            while (true) {
                foreach ([1 => &$stdout, 2 => &$stderr] as $index => &$buffer) {
                    if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                        continue;
                    }
                    $chunk = stream_get_contents($pipes[$index]);
                    if (is_string($chunk) && $chunk !== '') {
                        $buffer .= $chunk;
                    }
                    if (feof($pipes[$index])) {
                        fclose($pipes[$index]);
                        unset($pipes[$index]);
                    }
                }
                unset($buffer);
                $status = proc_get_status($process);
                if ($exitCode === null && ! $status['running']) {
                    $exitCode = (int) $status['exitcode'];
                }
                if (! isset($pipes[1]) && ! isset($pipes[2])) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    $this->stopCurlProcess($process);
                    break;
                }
                usleep(20000);
            }

            if ($exitCode === null && is_resource($process)) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exitCode = (int) $status['exitcode'];
                }
            }
            $this->closeCurlProcess($process, $pipes);
            $exitCode ??= 1;
            if ($timedOut) {
                throw new ConnectionException('Spoke request exceeded the configured timeout.');
            }
            if ($exitCode !== 0) {
                $message = trim($stderr);

                throw new ConnectionException($message !== '' ? $message : 'Spoke request failed.');
            }

            $httpStatus = (int) trim($stdout);
            if ($httpStatus <= 0) {
                throw new ConnectionException(trim($stderr) !== '' ? trim($stderr) : 'Spoke request failed.');
            }

            return new \Illuminate\Http\Client\Response(new Response(
                $httpStatus,
                $this->parseCurlHeaderDump((string) file_get_contents($headerFile)),
                (string) file_get_contents($bodyFile),
            ));
        } finally {
            $this->closeCurlProcess($process, $pipes);
            @unlink($headerFile);
            @unlink($bodyFile);
        }
    }

    private function curlBinary(): ?string
    {
        $configured = getenv('SPOKE_CURL_BIN');
        $candidates = is_string($configured) && $configured !== '' ? [$configured] : [];
        $candidates[] = '/usr/bin/curl';
        $candidates[] = '/opt/homebrew/bin/curl';
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function curlConfigQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  array<int, resource|null>  $pipes
     */
    private function closeCurlProcess(mixed &$process, array &$pipes): void
    {
        foreach ($pipes as $index => $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
            unset($pipes[$index]);
        }
        if (! is_resource($process)) {
            return;
        }
        proc_close($process);
        $process = null;
    }

    private function stopCurlProcess(mixed $process): void
    {
        if (! is_resource($process)) {
            return;
        }
        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        if (! $status['running'] || $pid <= 1) {
            return;
        }
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15);
            $grace = microtime(true) + 0.5;
            while (microtime(true) < $grace && proc_get_status($process)['running']) {
                usleep(20000);
            }
            if (proc_get_status($process)['running']) {
                posix_kill($pid, 9);
            }

            return;
        }
        proc_terminate($process, 15);
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseCurlHeaderDump(string $raw): array
    {
        $blocks = preg_split("/\r\n\r\n|\n\n|\r\r/", trim($raw)) ?: [];
        $last = trim((string) (end($blocks) ?: ''));
        $parsed = [];
        foreach (preg_split("/\r\n|\n|\r/", $last) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $parsed[trim($name)][] = trim($value);
        }

        return $parsed;
    }

    private function historicalIntegrationBaseUrl(): ?string
    {
        $baseUrl = rtrim((string) ($this->config()['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $parsed = parse_url($baseUrl);
        $scheme = strtolower((string) (is_array($parsed) ? ($parsed['scheme'] ?? '') : ''));
        $host = trim((string) (is_array($parsed) ? ($parsed['host'] ?? '') : ''));

        if ($scheme === 'https' && $host !== '') {
            return $baseUrl;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = config('order_lookup.spokes.'.$this->source, []);

        return is_array($config) ? $config : [];
    }

    private function interpretResponse(string $orderId, int $status, mixed $payload, ?string $retryAfter): RdServiceFetchResult
    {
        if ($status === 401) {
            Log::error('Spoke order lookup authentication failed.', [
                'source' => $this->source,
                'order_id' => $orderId,
                'http_status' => 401,
            ]);

            return new RdServiceFetchResult(
                retriable: false,
                fallbackToAdmin: false,
                errorMessage: 'Spoke authentication failed.',
                errorType: 'unauthorized',
                httpStatus: 401,
            );
        }

        if ($status === 404) {
            return $this->notFound('Spoke order not found.', 404);
        }

        if ($status === 429) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: 'Spoke API rate limit exceeded (HTTP 429).',
                errorType: 'rate_limited',
                httpStatus: 429,
                retryAfterSeconds: ctype_digit((string) $retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($status >= 500 || $status === 408) {
            return new RdServiceFetchResult(
                retriable: true,
                fallbackToAdmin: false,
                errorMessage: 'Spoke API request failed with HTTP '.$status.'.',
                errorType: 'http_error',
                httpStatus: $status,
            );
        }

        if ($status !== 200 || ! is_array($payload)) {
            return $this->malformed('Spoke API returned HTTP '.$status.'.', $status);
        }

        try {
            $enrichment = $this->mapper->map($payload, $orderId);
        } catch (RadiumBoxOrderNotFoundException $exception) {
            return $this->notFound($this->redact($exception->getMessage()), $status);
        } catch (RadiumBoxInvalidResponseException $exception) {
            return $this->malformed($this->redact($exception->getMessage()), $status);
        }

        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            enrichment: $enrichment,
            httpStatus: $status,
        );
    }

    private function skip(string $errorType, string $message): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            errorMessage: $message,
            errorType: $errorType,
        );
    }

    private function notFound(string $message, ?int $httpStatus = null): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            errorMessage: $message,
            errorType: 'order_not_found',
            httpStatus: $httpStatus,
        );
    }

    private function malformed(string $message, ?int $httpStatus = null): RdServiceFetchResult
    {
        return new RdServiceFetchResult(
            retriable: false,
            fallbackToAdmin: false,
            errorMessage: $message,
            errorType: 'invalid_response',
            httpStatus: $httpStatus,
        );
    }

    private function originBaseUrl(): ?string
    {
        $baseUrl = rtrim((string) ($this->config()['base_url'] ?? ''), '/');
        $lower = strtolower($baseUrl);

        if ($baseUrl === '') {
            return null;
        }

        if (str_starts_with($lower, 'https://')) {
            return $baseUrl;
        }

        if (preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $lower) !== 1) {
            return null;
        }

        return $this->requestHostHeader() === null ? null : $baseUrl;
    }

    private function requestHostHeader(): ?string
    {
        $host = trim((string) ($this->config()['host'] ?? ''));

        if ($host === '' || preg_match('/^[A-Za-z0-9.-]+\z/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    private function token(): string
    {
        return trim((string) ($this->config()['token'] ?? ''));
    }

    private function redact(string $message): string
    {
        $token = $this->token();

        if ($token !== '' && str_contains($message, $token)) {
            return str_replace($token, '[redacted]', $message);
        }

        return $message;
    }
}
