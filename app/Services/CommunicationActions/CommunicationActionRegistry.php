<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Enums\CommunicationActionKey;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CommunicationActionRegistry
{
    /**
     * @var Collection<string, CommunicationActionDefinition>|null
     */
    private ?Collection $definitions = null;

    /**
     * @return Collection<string, CommunicationActionDefinition>
     */
    public function all(): Collection
    {
        return $this->definitions();
    }

    public function get(CommunicationActionKey|string $key): CommunicationActionDefinition
    {
        $resolvedKey = $key instanceof CommunicationActionKey
            ? $key->value
            : $key;

        $definition = $this->definitions()->get($resolvedKey);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown communication action [{$resolvedKey}].");
        }

        return $definition;
    }

    public function has(CommunicationActionKey|string $key): bool
    {
        $resolvedKey = $key instanceof CommunicationActionKey
            ? $key->value
            : $key;

        return $this->definitions()->has($resolvedKey);
    }

    /**
     * @return Collection<string, CommunicationActionDefinition>
     */
    private function definitions(): Collection
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = collect(config('communication_actions.actions', []))
            ->map(fn (array $config): CommunicationActionDefinition => CommunicationActionDefinition::fromConfig($config));

        return $this->definitions;
    }
}
