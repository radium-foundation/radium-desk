@props([
    'saveFormId' => null,
    'toggleUrl' => null,
    'isEnabled' => true,
    'entityLabel' => 'item',
])

<div class="dropdown settings-center-table-actions">
    <button type="button"
            class="btn btn-sm btn-outline-secondary settings-center-table-actions__trigger"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            aria-label="Actions for this {{ $entityLabel }}">
        {!! \App\Support\Settings\SettingsIcon::render('more-vertical', 'settings-center-icon settings-center-icon--sm') !!}
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        @if($saveFormId)
            <li>
                <button type="submit" class="dropdown-item" form="{{ $saveFormId }}">Save changes</button>
            </li>
        @endif
        @if($toggleUrl)
            <li>
                <form method="POST" action="{{ $toggleUrl }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="dropdown-item">
                        {{ $isEnabled ? 'Disable' : 'Enable' }}
                    </button>
                </form>
            </li>
        @endif
    </ul>
</div>
