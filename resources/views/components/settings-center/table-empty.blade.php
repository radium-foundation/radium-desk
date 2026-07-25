@props([
    'colspan' => 1,
    'message' => 'No records found.',
])

<tr>
    <td colspan="{{ $colspan }}" class="settings-center-table__empty">
        <div class="settings-center-table__empty-inner">
            {!! \App\Support\Settings\SettingsIcon::render('inbox', 'settings-center-icon settings-center-table__empty-icon') !!}
            <span>{{ $message }}</span>
        </div>
    </td>
</tr>
