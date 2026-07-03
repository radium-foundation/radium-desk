<div class="customer-360-device-section"
     data-customer-360-device-section
     @if($device['should_poll_sync'] ?? false)
         data-should-poll-sync="true"
         data-device-refresh-url="{{ $device['device_refresh_url'] }}"
     @endif>
    @include('customer-360.partials.current-device', ['device' => $device])
    @include('customer-360.partials.sync-history', ['sync_history' => $sync_history ?? []])
</div>
