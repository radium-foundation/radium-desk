@props([
    'lastUpdated' => null,
    'environment' => null,
    'compact' => false,
])

<header class="system-settings-header @if($compact) system-settings-header--compact @endif" data-system-settings-header>
    @if(! $compact)
        <div class="system-settings-header__main">
            <div class="system-settings-header__titles">
                <h1 class="system-settings-header__title">System Settings</h1>
                <p class="system-settings-header__subtitle">
                    Manage operational behaviour, integrations and platform configuration.
                </p>
            </div>
    @endif

    <div class="system-settings-header__actions @if($compact) system-settings-header__actions--full @endif">
            <div class="system-settings-header__search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search"
                       class="form-control form-control-sm"
                       placeholder="Search settings…"
                       aria-label="Search settings"
                       data-system-settings-search
                       autocomplete="off">
            </div>

            @if($lastUpdated)
                <div class="system-settings-header__meta" title="Most recent setting change">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>Updated {{ $lastUpdated->timezone(config('app.timezone'))->diffForHumans() }}</span>
                </div>
            @endif

            @if($environment)
                <span @class([
                    'system-settings-env-badge',
                    'system-settings-env-badge--production' => $environment === 'production',
                    'system-settings-env-badge--staging' => in_array($environment, ['staging', 'stage'], true),
                    'system-settings-env-badge--local' => in_array($environment, ['local', 'development', 'dev'], true),
                ])>
                    {{ strtoupper($environment) }}
                </span>
            @endif

            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-system-settings-audit-open
                    aria-controls="system-settings-audit-drawer"
                    aria-expanded="false">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <span class="d-none d-md-inline">Audit History</span>
            </button>

            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-system-settings-discard-header
                    hidden>
                Reset
            </button>

            <button type="submit"
                    class="btn btn-sm btn-primary"
                    data-system-settings-save-header
                    hidden>
                Save Changes
            </button>
        </div>
    @if(! $compact)
        </div>
    @endif
</header>
