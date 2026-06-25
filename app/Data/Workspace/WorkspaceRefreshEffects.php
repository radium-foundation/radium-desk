<?php

namespace App\Data\Workspace;

readonly class WorkspaceRefreshEffects
{
    /**
     * @param  list<string>  $targetSelectors
     */
    public function __construct(
        public bool $refreshKpis = false,
        public bool $replaceRow = false,
        public array $targetSelectors = [],
        public bool $closeWorkspaceHost = true,
        public bool $preferRedirect = false,
        public bool $renderFragmentInHost = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'refresh_kpis' => $this->refreshKpis,
            'replace_row' => $this->replaceRow,
            'target_selectors' => $this->targetSelectors,
            'close_workspace_host' => $this->closeWorkspaceHost,
            'prefer_redirect' => $this->preferRedirect,
            'render_fragment_in_host' => $this->renderFragmentInHost,
        ];
    }
}
