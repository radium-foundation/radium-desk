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
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) strrchr((string) $address, ':'), 1);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            $ready = false;
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($ready);

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
            if (is_resource($process)) {
                proc_terminate($process, 15);
                proc_close($process);
            }
            @unlink($router);
        }
    }
}
