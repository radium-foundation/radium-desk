<x-settings-center.card title="Search Fields" description="Choose which order fields global search may match." icon="search">
    <form method="POST" action="{{ route('settings.search.update') }}" class="settings-center-form">
        @csrf
        @method('PUT')
        <p class="settings-center-helper">Future API integrations will respect these toggles.</p>
        <div class="settings-center-toggle-list">
            <div class="settings-center-toggle-row">
                <label class="settings-center-toggle-row__label" for="order_id_enabled">Order ID</label>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" name="order_id_enabled" value="1" id="order_id_enabled" class="form-check-input"
                           @checked(old('order_id_enabled', $search['order_id_enabled']))>
                </div>
            </div>
            <div class="settings-center-toggle-row">
                <label class="settings-center-toggle-row__label" for="serial_number_enabled">Serial Number</label>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" name="serial_number_enabled" value="1" id="serial_number_enabled" class="form-check-input"
                           @checked(old('serial_number_enabled', $search['serial_number_enabled']))>
                </div>
            </div>
            <div class="settings-center-toggle-row">
                <label class="settings-center-toggle-row__label" for="transaction_id_enabled">Service Reference</label>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" name="transaction_id_enabled" value="1" id="transaction_id_enabled" class="form-check-input"
                           @checked(old('transaction_id_enabled', $search['transaction_id_enabled']))>
                </div>
            </div>
            <div class="settings-center-toggle-row">
                <label class="settings-center-toggle-row__label" for="email_enabled">Email</label>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" name="email_enabled" value="1" id="email_enabled" class="form-check-input"
                           @checked(old('email_enabled', $search['email_enabled']))>
                </div>
            </div>
            <div class="settings-center-toggle-row">
                <label class="settings-center-toggle-row__label" for="mobile_enabled">Mobile</label>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" name="mobile_enabled" value="1" id="mobile_enabled" class="form-check-input"
                           @checked(old('mobile_enabled', $search['mobile_enabled']))>
                </div>
            </div>
        </div>
        <div class="settings-center-card__footer settings-center-card__footer--inline">
            <button type="submit" class="btn btn-primary">Save Search Settings</button>
        </div>
    </form>
</x-settings-center.card>
