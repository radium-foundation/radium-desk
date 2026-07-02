<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Notifications</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.notifications.update') }}">
            @csrf
            @method('PUT')
            <div class="vstack gap-3">
                <p class="text-muted small mb-0">
                    Email, WhatsApp, Telegram, and desktop delivery channels are managed in
                    <a href="{{ route('admin.system-settings.index') }}">System Settings</a>.
                </p>
                <div class="form-check form-switch">
                    <input type="checkbox" name="assignment_enabled" value="1" id="assignment_enabled" class="form-check-input"
                           @checked(old('assignment_enabled', $notifications['assignment_enabled']))>
                    <label class="form-check-label" for="assignment_enabled">Assignment notifications</label>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" name="transaction_enabled" value="1" id="transaction_enabled" class="form-check-input"
                           @checked(old('transaction_enabled', $notifications['transaction_enabled']))>
                    <label class="form-check-label" for="transaction_enabled">Transaction notifications</label>
                </div>
                <div class="form-check form-switch">
                    <input type="checkbox" name="high_priority_enabled" value="1" id="high_priority_enabled" class="form-check-input"
                           @checked(old('high_priority_enabled', $notifications['high_priority_enabled']))>
                    <label class="form-check-label" for="high_priority_enabled">High priority notifications</label>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save Notification Settings</button>
            </div>
        </form>
    </div>
</div>
