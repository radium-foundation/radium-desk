<?php

namespace App\Services;

use App\Contracts\GlobalSearchProvider;
use App\Data\GlobalSearchResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
            ->flatMap(function (GlobalSearchProvider $provider) use ($user, $query): Collection {
                try {
                    return $provider->search($user, $query);
                } catch (\Throwable $exception) {
                    Log::warning('global_search.provider_failed', [
                        'provider' => $provider->type(),
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);

                    return collect();
                }
            })
            ->values();
    }
}
