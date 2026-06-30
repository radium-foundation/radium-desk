<?php

namespace App\Contracts;

use App\Data\GlobalSearchResult;
use App\Models\User;
use Illuminate\Support\Collection;

interface GlobalSearchProvider
{
    public function type(): string;

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public function search(User $user, string $query): Collection;
}
