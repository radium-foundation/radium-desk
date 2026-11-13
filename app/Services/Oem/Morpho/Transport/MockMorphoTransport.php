<?php

namespace App\Services\Oem\Morpho\Transport;

use App\Services\Oem\Morpho\Contracts\MorphoTransport;
use App\Services\Oem\Morpho\Data\MorphoRawResponse;

/**
 * Synthetic OTBS-shaped responses for tests and offline development.
 * Never performs HTTP.
 */
final class MockMorphoTransport implements MorphoTransport
{
    /** @var array<string, callable(): MorphoRawResponse> */
    private array $getHandlers = [];

    /** @var array<string, callable(array<string, mixed>): MorphoRawResponse> */
    private array $postHandlers = [];

    public function registerGet(string $pathPattern, callable $handler): void
    {
        $this->getHandlers[$pathPattern] = $handler;
    }

    public function registerPost(string $pathPattern, callable $handler): void
    {
        $this->postHandlers[$pathPattern] = $handler;
    }

    public function get(string $path, array $headers = []): MorphoRawResponse
    {
        foreach ($this->getHandlers as $pattern => $handler) {
            if ($this->pathMatches($pattern, $path)) {
                return $handler();
            }
        }

        return $this->defaultGet($path);
    }

    public function post(string $path, array $payload, array $headers = []): MorphoRawResponse
    {
        foreach ($this->postHandlers as $pattern => $handler) {
            if ($this->pathMatches($pattern, $path)) {
                return $handler($payload);
            }
        }

        return $this->defaultPost($path, $payload);
    }

    private function pathMatches(string $pattern, string $path): bool
    {
        if ($pattern === $path) {
            return true;
        }

        if (str_ends_with($pattern, '*')) {
            return str_starts_with($path, rtrim($pattern, '*'));
        }

        return false;
    }

    private function defaultGet(string $path): MorphoRawResponse
    {
        if (preg_match('#/apiconnector/DeviceValidator/(0000I000001)$#', $path, $m)) {
            return new MorphoRawResponse(200, [
                'success' => true,
                'content' => [[
                    'responseCode' => '000',
                    'serialNo' => $m[1],
                ]],
            ]);
        }

        if (preg_match('#/apiconnector/DeviceValidator/(0000I000099)$#', $path, $m)) {
            return new MorphoRawResponse(200, [
                'success' => true,
                'content' => [[
                    'responseCode' => '001',
                    'serialNo' => $m[1],
                ]],
            ]);
        }

        if (preg_match('#/apiconnector/DeviceValidator/(0000I000401)$#', $path)) {
            return new MorphoRawResponse(401, null, body: '');
        }

        if (preg_match('#/apiconnector/DeviceValidator/(0000I000502)$#', $path)) {
            return new MorphoRawResponse(0, null, timedOut: true);
        }

        if (preg_match('#/apiconnector/DeviceValidator/(0000I000888)$#', $path)) {
            return new MorphoRawResponse(200, ['content' => 'not-an-array']);
        }

        return new MorphoRawResponse(404, null, body: 'not found');
    }

    private function defaultPost(string $path, array $payload): MorphoRawResponse
    {
        $serial = is_string($payload['serialNo'] ?? null) ? $payload['serialNo'] : '';

        if (str_contains($path, 'GetRDServiceDeviceStatus')) {
            if ($serial === '0000I000002') {
                return new MorphoRawResponse(200, [
                    'success' => true,
                    'content' => [[
                        [
                            'SerialNo' => $serial,
                            'Massage' => '000',
                            'DeviceType' => 'MSO1300E3',
                        ],
                    ]],
                ]);
            }

            if ($serial === '0000I000003') {
                return new MorphoRawResponse(200, [
                    'success' => true,
                    'content' => [[
                        [
                            'SerialNo' => $serial,
                            'Massage' => '002',
                            'DeviceType' => 'MSO1300E3',
                        ],
                    ]],
                ]);
            }

            return new MorphoRawResponse(200, [
                'success' => true,
                'content' => [[]],
            ]);
        }

        if (str_contains($path, 'RDServiceShopifyOrderData')) {
            $lookup = '';
            $raw = (string) ($payload['serialnumbers'] ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $lookup = (string) ($decoded['serialnumbers'] ?? '');
                }
            }

            if ($lookup === '0000I000004') {
                return new MorphoRawResponse(200, [
                    'success' => true,
                    'content' => [[
                        [
                            'OrdersNumber' => 'ORD-SYN-100',
                            'SerialNo' => $lookup,
                            'WLDateTime' => '2025-01-01',
                            'ExpiryDate' => '2026-01-01',
                            'NumberofYear' => '1',
                        ],
                    ]],
                ]);
            }

            if ($lookup === '0000I000005') {
                return new MorphoRawResponse(200, [
                    'success' => true,
                    'content' => [[]],
                ]);
            }

            return new MorphoRawResponse(503, null, body: 'unavailable');
        }

        return new MorphoRawResponse(404, null);
    }
}
