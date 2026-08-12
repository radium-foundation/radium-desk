@props([
    'user',
    'telegramSettings' => [],
])

@php
    $connected = (bool) ($telegramSettings['connected'] ?? false);
    $notificationsEnabled = (bool) ($telegramSettings['notifications_enabled'] ?? false);
    $chatId = $telegramSettings['chat_id'] ?? null;
@endphp

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Telegram Notifications</h2>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Manage this user's Telegram connection for Ira operational alerts.
        </p>

        <div class="d-flex flex-wrap gap-2 mb-3">
            @if($connected)
                <span class="badge text-bg-success">Connected</span>
            @else
                <span class="badge text-bg-warning">Not connected</span>
            @endif
            @if($notificationsEnabled)
                <span class="badge text-bg-primary">Notifications enabled</span>
            @else
                <span class="badge text-bg-secondary">Notifications disabled</span>
            @endif
        </div>

        <form method="POST" action="{{ route('users.telegram.update', $user) }}" id="user-telegram-settings-form">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="admin_telegram_chat_id" class="form-label">Telegram Chat ID</label>
                <input
                    type="text"
                    id="admin_telegram_chat_id"
                    name="telegram_chat_id"
                    class="form-control @error('telegram_chat_id') is-invalid @enderror"
                    value="{{ old('telegram_chat_id', $chatId) }}"
                    placeholder="e.g. 123456789"
                >
                @error('telegram_chat_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-check mb-3">
                <input type="hidden" name="telegram_notifications_enabled" value="0">
                <input
                    type="checkbox"
                    class="form-check-input @error('telegram_notifications_enabled') is-invalid @enderror"
                    id="admin_telegram_notifications_enabled"
                    name="telegram_notifications_enabled"
                    value="1"
                    @checked(old('telegram_notifications_enabled', $notificationsEnabled))
                >
                <label class="form-check-label" for="admin_telegram_notifications_enabled">
                    Enable Telegram notifications
                </label>
                @error('telegram_notifications_enabled')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Save Telegram settings</button>
            </div>
        </form>

        <form
            method="POST"
            action="{{ route('users.telegram.update', $user) }}"
            class="mt-3"
            onsubmit="return confirm('Reset Telegram for this user? This clears the chat ID and disables notifications.');"
        >
            @csrf
            @method('PUT')
            <input type="hidden" name="reset" value="1">
            <button type="submit" class="btn btn-outline-danger">Reset Telegram</button>
        </form>
    </div>
</div>
