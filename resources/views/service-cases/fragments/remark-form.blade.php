@php
    $listId = 'mention-users-note-'.md5($incident::class.$incident->getKey());
    $bodyValue = $remarkBody ?? old('body');
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl ?? route('remarks.store') }}"
      @if($workspaceActionUrl ?? null) data-workspace-action-form="remark" @endif
      class="workspace-note-dialog">
    @csrf
    @unless($workspaceActionUrl ?? null)
        <input type="hidden" name="remarkable_type" value="{{ $incident::class }}">
        <input type="hidden" name="remarkable_id" value="{{ $incident->getKey() }}">
    @endunless
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0" id="noteModalLabel">Add Note</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body workspace-note-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert" data-workspace-validation-summary>
                {{ $errors->first() }}
            </div>
        @endif

        <label for="modal_note_body" class="form-label">Note <span class="text-danger">*</span></label>
        <textarea
            name="body"
            id="modal_note_body"
            rows="3"
            class="form-control form-control-sm @error('body') is-invalid @enderror"
            placeholder="Add a note… Use @Name to mention someone."
            data-mention-textarea
            data-mention-list="{{ $listId }}"
            required
        >{{ $bodyValue }}</textarea>
        <datalist id="{{ $listId }}">
            @foreach($mentionUsers as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        @error('body')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror

        <fieldset class="workspace-note-notify mt-3 mb-0" aria-label="Customer notification">
            <legend class="form-label mb-2">Notify Customer</legend>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           name="notify_whatsapp"
                           value="1"
                           id="workspace_note_notify_whatsapp">
                    <label class="form-check-label" for="workspace_note_notify_whatsapp">WhatsApp</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           name="notify_email"
                           value="1"
                           id="workspace_note_notify_email">
                    <label class="form-check-label" for="workspace_note_notify_email">Email</label>
                </div>
            </div>
        </fieldset>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary px-4">Save Note</button>
    </div>
</form>
