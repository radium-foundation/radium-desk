@props([
    'teamAvailability' => ['on_duty' => [], 'unavailable' => []],
    'teamTelegramStatus' => [],
])

<div class="operations-team-tab-content">
    <div id="operations-team-availability">
        @include('admin.operations.partials.team-availability', ['teamAvailability' => $teamAvailability])
    </div>

    <div id="operations-team-telegram-status" class="mt-4">
        @include('admin.operations.partials.team-telegram-status', ['members' => $teamTelegramStatus])
    </div>
</div>
