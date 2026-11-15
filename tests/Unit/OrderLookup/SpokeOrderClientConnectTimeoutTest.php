<?php

declare(strict_types=1);

namespace Tests\Unit\OrderLookup;

use App\Services\OrderLookup\SpokeOrderClient;
use App\Services\RdService\RdServiceOrderMapper;
use Tests\TestCase;

class SpokeOrderClientConnectTimeoutTest extends TestCase
{
    public function test_unresolvable_spoke_lookup_returns_within_the_connect_budget(): void
    {
        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'box-token',
            'order_lookup.spokes.radiumbox_com.connect_timeout_seconds' => 3,
            'order_lookup.spokes.radiumbox_com.timeout_seconds' => 8,
        ]);

        $client = new SpokeOrderClient(app(RdServiceOrderMapper::class), 'radiumbox_com');
        $started = microtime(true);
        $payload = $client->fetchPayload('RB1092');
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(4.0, $elapsed);
        $this->assertNull($payload);
        $this->assertTrue(
            is_executable('/usr/bin/curl') || is_executable('/opt/homebrew/bin/curl'),
            'The connect budget is enforced by the curl executable.',
        );
    }

    public function test_local_spoke_payload_is_read_from_the_bounded_transport(): void
    {
        $router = tempnam(sys_get_temp_dir(), 'spoke-router-');
        $this->assertIsString($router);
        file_put_contents($router, <<<'PHP'
<?php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($uri !== '/api/integrations/v1/rd-orders/RB1092') {
    http_response_code(404);
    echo '{"missing":true}';
    return true;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer box-token') {
    http_response_code(401);
    echo '{"error":"auth"}';
    return true;
}
header('Content-Type: application/json');
echo '{"id":"RB1092","ok":true}';
return true;
PHP);
        $server = $this->startLoopbackSpokeRouter($router);
        $process = $server['process'];
        $port = $server['port'];

        try {
            config([
                'order_lookup.spokes.radiumbox_com.enabled' => true,
                'order_lookup.spokes.radiumbox_com.base_url' => 'http://127.0.0.1:'.$port,
                'order_lookup.spokes.radiumbox_com.host' => '127.0.0.1',
                'order_lookup.spokes.radiumbox_com.token' => 'box-token',
                'order_lookup.spokes.radiumbox_com.connect_timeout_seconds' => 3,
                'order_lookup.spokes.radiumbox_com.timeout_seconds' => 8,
            ]);

            $client = new SpokeOrderClient(app(RdServiceOrderMapper::class), 'radiumbox_com');
            $payload = $client->fetchPayload('RB1092');

            $this->assertSame(['id' => 'RB1092', 'ok' => true], $payload);
        } finally {
            $this->stopLoopbackSpokeRouter($process, $server['stderr_file'] ?? null);
            @unlink($router);
        }
    }

    /**
     * @return array{process: resource, port: int, stderr_file: string}
     */
    private function startLoopbackSpokeRouter(string $router): array
    {
        $phpBinary = getenv('DESK_PHP_BIN');
        if (! is_string($phpBinary) || $phpBinary === '') {
            $phpBinary = PHP_BINARY;
        }

        $bootBudgetSeconds = 2.0;
        $lastStderr = '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $port = random_int(49152, 65535);
            $stderrFile = (string) tempnam(sys_get_temp_dir(), 'spoke-php-s-err-');
            $process = proc_open(
                [$phpBinary, '-S', '127.0.0.1:'.$port, $router],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
            );
            $this->assertNotFalse($process, 'Failed to start the loopback spoke router process.');

            $deadline = microtime(true) + $bootBudgetSeconds;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                $lastStderr = is_readable($stderrFile) ? (string) file_get_contents($stderrFile) : '';
                if (str_contains($lastStderr, 'Development Server')) {
                    return [
                        'process' => $process,
                        'port' => $port,
                        'stderr_file' => $stderrFile,
                    ];
                }
                if (! $status['running'] && (int) $status['exitcode'] !== -1) {
                    break;
                }
                usleep(10000);
            }

            $this->stopLoopbackSpokeRouter($process, $stderrFile);
        }

        $this->fail(
            'Loopback spoke router did not emit a Development Server banner within '
            .$bootBudgetSeconds."s across 8 port attempts. Last stderr:\n".$lastStderr
        );
    }

    private function stopLoopbackSpokeRouter(mixed $process, ?string $stderrFile): void
    {
        if (is_resource($process)) {
            proc_terminate($process, 15);
            proc_close($process);
        }
        if (is_string($stderrFile) && $stderrFile !== '') {
            @unlink($stderrFile);
        }
    }
}
