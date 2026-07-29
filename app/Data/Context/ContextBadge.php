<?php

namespace App\Data\Context;

use App\Enums\ContextScope;

/**
 * Lightweight presentation metadata for a scoped surface (BR-03 Phase 1).
 *
 * Not rendered in Phase 1 — available for future UI when
 * context_transparency.enabled is true.
 */
readonly class ContextBadge
{
    public function __construct(
        public ContextScope $scope,
        public string $label,
        public ?string $icon = null,
        public ?string $colorToken = null,
    ) {}

    public static function forScope(ContextScope $scope, ?string $label = null): self
    {
        return new self(
            scope: $scope,
            label: $label ?? $scope->label(),
            icon: $scope->defaultIcon(),
            colorToken: $scope->colorToken(),
        );
    }

    /**
     * @return array{scope: string, label: string, icon: ?string, color_token: ?string}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'label' => $this->label,
            'icon' => $this->icon,
            'color_token' => $this->colorToken,
        ];
    }
}
