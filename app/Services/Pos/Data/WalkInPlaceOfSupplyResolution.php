<?php

namespace App\Services\Pos\Data;

final class WalkInPlaceOfSupplyResolution
{
    public function __construct(
        public readonly string $state,
        public readonly string $source,
    ) {}
}
