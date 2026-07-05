<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Telegram Notifications</h2>
    </div>
    <div class="card-body">
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
                    value="{{ old('telegram_chat_id', $user->telegram_chat_id) }}"
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
                    @checked(old('telegram_notifications_enabled', $user->telegram_notifications_enabled))
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
    </div>
</div>
