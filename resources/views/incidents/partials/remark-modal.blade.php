@can('create', App\Models\Remark::class)
    @php
        $listId = 'mention-users-modal-'.md5($incident::class.$incident->getKey());
    @endphp

    <div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="remarkModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('remarks.store') }}">
                    @csrf
                    <input type="hidden" name="remarkable_type" value="{{ $incident::class }}">
                    <input type="hidden" name="remarkable_id" value="{{ $incident->getKey() }}">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="remarkModalLabel">Add Remark</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label for="modal_remark_body" class="form-label">Remark</label>
                        <textarea
                            name="body"
                            id="modal_remark_body"
                            rows="4"
                            class="form-control @error('body') is-invalid @enderror"
                            placeholder="Write a remark... Use @Name to mention someone."
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
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i> Add Remark
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @once
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    document.querySelectorAll('[data-mention-textarea]').forEach((textarea) => {
                        const listId = textarea.dataset.mentionList;
                        const datalist = listId ? document.getElementById(listId) : null;

                        textarea.addEventListener('input', () => {
                            if (!datalist) {
                                return;
                            }

                            const value = textarea.value;
                            const match = value.match(/@([\p{L}\p{M}'.]*)$/u);

                            if (!match) {
                                return;
                            }

                            const term = match[1].toLowerCase();
                            Array.from(datalist.options).forEach((option) => {
                                option.hidden = term !== '' && !option.value.toLowerCase().startsWith(term);
                            });
                        });
                    });
                });
            </script>
        @endpush
    @endonce
@endcan
