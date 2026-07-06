@props([
    'members' => [],
    'teamTelegramStatus' => [],
])

<div class="operations-team-tab-content">
    <div id="operations-team-availability">
        @include('admin.operations.partials.team-availability', ['members' => $members])
    </div>

    <div id="operations-team-telegram-status" class="mt-4">
        @include('admin.operations.partials.team-telegram-status', ['members' => $teamTelegramStatus])
    </div>
</div>
