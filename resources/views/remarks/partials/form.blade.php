@props([
    'remarkable',
    'mentionUsers',
])

@php
    $listId = 'mention-users-'.md5($remarkable::class.$remarkable->getKey());
@endphp

<form method="POST" action="{{ route('remarks.store') }}" class="mb-0">
    @csrf
    <input type="hidden" name="remarkable_type" value="{{ $remarkable::class }}">
    <input type="hidden" name="remarkable_id" value="{{ $remarkable->getKey() }}">

    <label for="remark_body_{{ $remarkable->getKey() }}" class="form-label">Add Remark</label>
    <textarea
        name="body"
        id="remark_body_{{ $remarkable->getKey() }}"
        rows="3"
        class="form-control @error('body') is-invalid @enderror"
        placeholder="Write a remark... Use @Name to mention someone (e.g. @Damini Please verify serial number.)"
        data-mention-textarea
        data-mention-list="{{ $listId }}"
        required
    >{{ old('body') }}</textarea>
    <datalist id="{{ $listId }}">
        @foreach($mentionUsers as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>
    @error('body')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">Minimum 3 characters. Mentions like @Damini are highlighted in the timeline.</div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-send me-1"></i> Add Remark
        </button>
    </div>
</form>
