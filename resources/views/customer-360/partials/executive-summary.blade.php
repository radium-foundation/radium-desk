@if(!empty($iraPanel))
    <x-c360.ira-command-center
        :panel="$iraPanel"
        :incident="$incident"
    />
@else
    <x-c360.empty-state
        icon="bi-stars"
        title="IRA unavailable"
        description="Case intelligence will load when IRA has enough data for this case."
        action-label="Open IRA AI"
        action-icon="bi-stars"
        data-c360-empty-open-tab="ai-assistant"
        class="c360-ira-command-center-empty"
    />
@endif
