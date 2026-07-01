@can('create', App\Models\Remark::class)
    <div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                @include('service-cases.fragments.remark-form', [
                    'incident' => $incident,
                    'mentionUsers' => $mentionUsers,
                ])
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
