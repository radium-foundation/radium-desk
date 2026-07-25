@props([
    'action' => null,
    'method' => 'GET',
    'searchName' => 'search',
    'searchValue' => null,
    'searchPlaceholder' => 'Search…',
    'clearUrl' => null,
    'hiddenFields' => [],
])

<form method="{{ $method }}"
      action="{{ $action }}"
      class="settings-center-table-toolbar"
      role="search">
    @foreach($hiddenFields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    <div class="settings-center-table-toolbar__search">
        {!! \App\Support\Settings\SettingsIcon::render('search', 'settings-center-icon settings-center-icon--sm') !!}
        <input type="search"
               name="{{ $searchName }}"
               class="form-control form-control-sm"
               placeholder="{{ $searchPlaceholder }}"
               value="{{ $searchValue }}"
               aria-label="{{ $searchPlaceholder }}">
    </div>

    <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>

    @if($clearUrl)
        <a href="{{ $clearUrl }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    @endif
</form>
