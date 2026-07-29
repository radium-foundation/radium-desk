<?php

namespace App\Data\Context;

use App\Enums\ContextScope;

/**
 * Internal annotation for a Customer 360 surface (BR-03 Phase 1).
 *
 * Catalog-only — not rendered. Intended scopes follow BR-02
 * (Case authoritative; Customer historical).
 */
readonly class Customer360CardDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public ContextScope $intendedScope,
        public string $surface,
        public ?string $notes = null,
    ) {}

    public function badge(): ContextBadge
    {
        return ContextBadge::forScope($this->intendedScope, $this->name);
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     intended_scope: string,
     *     surface: string,
     *     notes: ?string,
     *     badge: array{scope: string, label: string, icon: ?string, color_token: ?string}
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'intended_scope' => $this->intendedScope->value,
            'surface' => $this->surface,
            'notes' => $this->notes,
            'badge' => $this->badge()->toArray(),
        ];
    }
}
