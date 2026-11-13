<?php

namespace App\Services\Oem\Morpho\Contracts;

use App\Services\Oem\Morpho\Data\MorphoRawResponse;

interface MorphoTransport
{
    public function get(string $path, array $headers = []): MorphoRawResponse;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload, array $headers = []): MorphoRawResponse;
}
