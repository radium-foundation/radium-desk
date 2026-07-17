<?php

namespace App\Data\Operations;

use Illuminate\Support\Carbon;

readonly class Workforce360TeamData
{
    /**
     * @param  array<string, mixed>  $hero
     * @param  list<array<string, mixed>>  $capacity
     * @param  list<array<string, mixed>>  $members
     * @param  list<array<string, mixed>>  $tabs
     */
    public function __construct(
        public Carbon $asOf,
        public array $hero,
        public array $capacity,
        public array $members,
        public array $tabs,
        public array $teamAvailability,
    ) {}
}
