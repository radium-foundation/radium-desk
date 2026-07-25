<?php

namespace App\Support\Navigation;

readonly class NavigationContext
{
    /**
     * @param  list<array{label: string, url: ?string}>  $breadcrumbs
     */
    public function __construct(
        public ?NavigationMenu $menu,
        public ?string $activeItemKey,
        public string $pageTitle,
        public string $documentTitle,
        public array $breadcrumbs,
        public bool $showBreadcrumb,
        public ?string $resolvedMenuHomeUrl = null,
    ) {}

    public function isActive(string $itemKey): bool
    {
        return $this->activeItemKey === $itemKey;
    }

    public function menuLabel(): ?string
    {
        return $this->menu?->label();
    }

    public function menuHomeUrl(): ?string
    {
        if ($this->resolvedMenuHomeUrl !== null) {
            return $this->resolvedMenuHomeUrl;
        }

        if ($this->menu === null) {
            return null;
        }

        return route($this->menu->homeRoute());
    }
}
