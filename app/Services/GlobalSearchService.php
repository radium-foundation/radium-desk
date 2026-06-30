<?php

namespace App\Services;

use App\Contracts\GlobalSearchProvider;
use App\Data\GlobalSearchResult;
use App\Models\User;
use Illuminate\Support\Collection;

class GlobalSearchService
{
    /**
     * @param  iterable<GlobalSearchProvider>  $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public function search(User $user, string $query): Collection
    {
        $query = trim($query);

        if ($query === '' || ! $user->can('incidents.view')) {
            return collect();
        }

        return collect($this->providers)
            ->flatMap(fn (GlobalSearchProvider $provider): Collection => $provider->search($user, $query))
            ->values();
    }
}
