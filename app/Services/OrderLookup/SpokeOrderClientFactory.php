<?php

namespace App\Services\OrderLookup;

use App\Services\RdService\RdServiceOrderMapper;

class SpokeOrderClientFactory
{
    public function __construct(
        private readonly RdServiceOrderMapper $mapper,
    ) {}

    public function make(string $source): SpokeOrderClient
    {
        return new SpokeOrderClient($this->mapper, $source);
    }

    /**
     * @return list<SpokeOrderClient>
     */
    public function configured(): array
    {
        $clients = [];
        foreach (array_keys(config('order_lookup.spokes', [])) as $source) {
            if (! is_string($source)) {
                continue;
            }
            $client = $this->make($source);
            if ($client->isConfigured()) {
                $clients[] = $client;
            }
        }

        return $clients;
    }
}
