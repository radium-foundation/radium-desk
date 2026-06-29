@php
    $listId = 'mention-users-'.$action.'-'.md5($incident::class.$incident->getKey());
    $textareaId = $action.'_remark_body';
@endphp

<div class="mb-0">
    @if($description ?? null)
        <p class="text-muted small mb-3">{{ $description }}</p>
    @endif

    <label for="{{ $textareaId }}" class="form-label">Remark <span class="text-danger">*</span></label>
    <textarea
        name="body"
        id="{{ $textareaId }}"
        rows="4"
        class="form-control @error('body') is-invalid @enderror"
        placeholder="Describe what was done... Use @Name to mention someone."
        data-mention-textarea
        data-mention-list="{{ $listId }}"
        required
    >{{ $remarkBody ?? old('body') }}</textarea>
    <datalist id="{{ $listId }}">
        @foreach($mentionUsers as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>
    @error('body')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">Required. Mentions like @Damini are highlighted in the timeline.</div>
</div>
