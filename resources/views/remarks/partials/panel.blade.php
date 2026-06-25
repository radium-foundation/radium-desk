@props([
    'remarkable',
    'timelineRemarks',
    'showContextBadge' => false,
])

@php
    use App\Services\RemarkMentionFormatter;
    use App\Services\RemarkTimelineService;

    $mentionFormatter = app(RemarkMentionFormatter::class);
    $timelineService = app(RemarkTimelineService::class);
    $mentionUsers = \App\Models\User::query()
        ->where('is_active', true)
        ->orderBy('name')
        ->pluck('name');
@endphp

<div class="card border-0 shadow-sm" id="remarks-timeline">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Remarks Timeline</h2>
    </div>
    <div class="card-body">
        @can('create', App\Models\Remark::class)
            @include('remarks.partials.form', ['remarkable' => $remarkable, 'mentionUsers' => $mentionUsers])
        @endcan

        @if($timelineRemarks->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-chat-left-text fs-4 d-block mb-2"></i>
                <p class="mb-0">No remarks yet.</p>
            </div>
        @else
            <div class="remarks-timeline mt-4">
                @foreach($timelineRemarks as $remark)
                    <div class="remarks-timeline-item">
                        <div class="remarks-timeline-marker"></div>
                        <div class="remarks-timeline-content">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                                <div>
                                    <div class="remarks-timeline-datetime">
                                        {{ display_app_remark_datetime($remark->created_at) }}
                                    </div>
                                    <div class="fw-semibold">{{ $remark->user?->name ?? 'Unknown User' }}</div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    @if($showContextBadge)
                                        <span class="badge text-bg-light text-dark border">
                                            {{ $timelineService->contextLabel($remark) }}
                                        </span>
                                    @endif
                                    @can('delete', $remark)
                                        <form method="POST" action="{{ route('remarks.destroy', $remark) }}"
                                              onsubmit="return confirm('Delete this remark?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete remark">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </div>
                            <div class="remarks-timeline-body">{!! $mentionFormatter->format($remark->body) !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
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
