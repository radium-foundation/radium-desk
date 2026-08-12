@props([
    'user',
    'telegramSettings' => [],
])

@php
    $canSelfServiceConnect = (bool) ($telegramSettings['can_self_service_connect'] ?? false);
    $isSelfServiceLocked = (bool) ($telegramSettings['is_self_service_locked'] ?? false);
    $connected = (bool) ($telegramSettings['connected'] ?? false);
    $notificationsEnabled = (bool) ($telegramSettings['notifications_enabled'] ?? false);
    $chatId = $telegramSettings['chat_id'] ?? null;
    $canManageUsers = auth()->user()?->can('users.manage') ?? false;
@endphp

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Telegram Notifications</h2>
    </div>
    <div class="card-body">
        @if($isSelfServiceLocked)
            <p class="text-muted small mb-3">
                Your Telegram connection is active. Ira operational alerts are delivered to the linked account.
            </p>

            <div class="mb-3">
                <label class="form-label">Telegram Chat ID</label>
                <input
                    type="text"
                    class="form-control"
                    value="{{ $chatId }}"
                    readonly
                    aria-readonly="true"
                >
            </div>

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

            <p class="text-muted small mb-0">
                To change or reset your Telegram connection, contact an administrator.
            </p>
        @elseif($canSelfServiceConnect)
            <p class="text-muted small mb-3">
                Connect Telegram to receive Ira operational alerts. Message
                <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a>
                to find your chat ID, then enter it below.
            </p>

            <form method="POST" action="{{ route('profile.telegram.update') }}">
                @csrf
                @method('patch')

                <div class="mb-3">
                    <label for="telegram_chat_id" class="form-label">Telegram Chat ID</label>
                    <input
                        type="text"
                        id="telegram_chat_id"
                        name="telegram_chat_id"
                        class="form-control @error('telegram_chat_id') is-invalid @enderror"
                        value="{{ old('telegram_chat_id', $chatId) }}"
                        placeholder="e.g. 123456789"
                        required
                    >
                    @error('telegram_chat_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">Connect Telegram</button>
            </form>
        @elseif($canManageUsers)
            <p class="text-muted small mb-3">
                Connect Telegram to receive Ira operational alerts. Message
                <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a>
                to find your chat ID, then enter it below.
            </p>

            <form method="POST" action="{{ route('profile.telegram.update') }}">
                @csrf
                @method('patch')

                <div class="mb-3">
                    <label for="telegram_chat_id" class="form-label">Telegram Chat ID</label>
                    <input
                        type="text"
                        id="telegram_chat_id"
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
                    <input
                        type="hidden"
                        name="telegram_notifications_enabled"
                        value="0"
                    >
                    <input
                        type="checkbox"
                        class="form-check-input @error('telegram_notifications_enabled') is-invalid @enderror"
                        id="telegram_notifications_enabled"
                        name="telegram_notifications_enabled"
                        value="1"
                        @checked(old('telegram_notifications_enabled', $notificationsEnabled))
                    >
                    <label class="form-check-label" for="telegram_notifications_enabled">
                        Enable Ira Telegram notifications
                    </label>
                    @error('telegram_notifications_enabled')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">Save Telegram settings</button>
            </form>
        @else
            <p class="text-muted small mb-0">
                Telegram notifications are not configured for your account.
            </p>
        @endif
    </div>
</div>
