<div class="settings-center-card">
    <div class="settings-center-card__header">
        <div class="settings-center-card__heading">
            <h2 class="settings-center-card__title">Notifications</h2>
            <p class="settings-center-card__description">Application-level notification preferences for operators.</p>
        </div>
    </div>
    <div class="settings-center-card__body">
        <form method="POST" action="{{ route('settings.notifications.update') }}">
            @csrf
            @method('PUT')
            <p class="text-muted small mb-3">
                Channel delivery (Email, WhatsApp, Telegram) is managed in the
                <a href="{{ route('admin.system-settings.index') }}#section-operational-center">Operational Center</a>.
            </p>
            <div class="settings-center-toggle-list">
                <div class="settings-center-toggle-row">
                    <div>
                        <label class="settings-center-toggle-row__label" for="assignment_enabled">Assignment notifications</label>
                        <p class="settings-center-toggle-row__hint">Notify agents when cases are assigned.</p>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" name="assignment_enabled" value="1" id="assignment_enabled" class="form-check-input"
                               @checked(old('assignment_enabled', $notifications['assignment_enabled']))>
                    </div>
                </div>
                <div class="settings-center-toggle-row">
                    <div>
                        <label class="settings-center-toggle-row__label" for="transaction_enabled">Transaction notifications</label>
                        <p class="settings-center-toggle-row__hint">Alerts for payment and transaction events.</p>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" name="transaction_enabled" value="1" id="transaction_enabled" class="form-check-input"
                               @checked(old('transaction_enabled', $notifications['transaction_enabled']))>
                    </div>
                </div>
                <div class="settings-center-toggle-row">
                    <div>
                        <label class="settings-center-toggle-row__label" for="high_priority_enabled">High priority notifications</label>
                        <p class="settings-center-toggle-row__hint">Escalated alerts for urgent service cases.</p>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" name="high_priority_enabled" value="1" id="high_priority_enabled" class="form-check-input"
                               @checked(old('high_priority_enabled', $notifications['high_priority_enabled']))>
                    </div>
                </div>
            </div>
            <div class="settings-center-card__footer settings-center-card__footer--inline">
                <button type="submit" class="btn btn-primary">Save Notification Settings</button>
            </div>
        </form>
    </div>
</div>
