<?php

namespace App\Data\Operations;

use App\Models\User;
use Illuminate\Support\Carbon;

readonly class Workforce360MemberData
{
    /**
     * @param  array<string, mixed>  $hero
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overview
     * @param  list<array<string, mixed>>  $tabs
     */
    public function __construct(
        public User $user,
        public Carbon $asOf,
        public bool $isSelf,
        public array $hero,
        public array $context,
        public array $overview,
        public array $tabs,
        public ?string $teamUrl,
    ) {}
}
