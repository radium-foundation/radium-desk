<?php

namespace App\Services\GlobalSearch;

use App\Contracts\GlobalSearchProvider;
use App\Data\GlobalSearchResult;
use App\Models\User;
use App\Services\StatutoryInvoice\StatutoryInvoiceForIncidentResolver;
use App\Support\StatutoryInvoice\StatutoryInvoiceNumber;
use Illuminate\Support\Collection;

class StatutoryInvoiceGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(
        private readonly StatutoryInvoiceForIncidentResolver $resolver,
        private readonly ServiceCaseGlobalSearchProvider $serviceCases,
    ) {}

    public function type(): string
    {
        return 'service_case';
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public function search(User $user, string $query): Collection
    {
        if (! StatutoryInvoiceNumber::looksLike($query)) {
            return collect();
        }

        $incident = $this->resolver->incidentForInvoiceNumber($query, $user);
        if ($incident === null) {
            return collect();
        }

        return collect([
            $this->serviceCases->resultFor($incident, $user),
        ]);
    }
}
