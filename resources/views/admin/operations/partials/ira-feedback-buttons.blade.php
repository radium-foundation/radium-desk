@props([
    'insightKey' => '',
    'insightType' => 'recommendation',
    'insightPayload' => [],
])

<div class="ira-feedback-buttons flex-shrink-0" data-insight-key="{{ $insightKey }}" data-insight-type="{{ $insightType }}">
    <div class="btn-group btn-group-sm" role="group" aria-label="Insight feedback">
        @foreach(\App\Enums\IraInsightFeedbackResponse::cases() as $response)
            <button
                type="button"
                class="btn btn-outline-secondary ira-feedback-btn"
                data-response="{{ $response->value }}"
                data-payload='@json($insightPayload)'
                title="{{ $response->label() }}"
            >
                {{ match($response) {
                    \App\Enums\IraInsightFeedbackResponse::Useful => '👍',
                    \App\Enums\IraInsightFeedbackResponse::Ignored => '—',
                    \App\Enums\IraInsightFeedbackResponse::Incorrect => '✕',
                } }}
            </button>
        @endforeach
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('click', async (event) => {
                const button = event.target.closest('.ira-feedback-btn');

                if (!button) {
                    return;
                }

                const container = button.closest('.ira-feedback-buttons');
                const insightKey = container?.dataset.insightKey;
                const insightType = container?.dataset.insightType;
                const response = button.dataset.response;
                let insightPayload = {};

                try {
                    insightPayload = JSON.parse(button.dataset.payload || '{}');
                } catch (error) {
                    insightPayload = {};
                }

                button.disabled = true;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                    const result = await fetch('{{ route('admin.operations.ira.feedback') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            insight_key: insightKey,
                            insight_type: insightType,
                            response,
                            insight_payload: insightPayload,
                        }),
                    });

                    if (result.ok) {
                        container.querySelectorAll('.ira-feedback-btn').forEach((peer) => {
                            peer.classList.remove('btn-primary');
                            peer.classList.add('btn-outline-secondary');
                            peer.disabled = true;
                        });
                        button.classList.remove('btn-outline-secondary');
                        button.classList.add('btn-primary');
                    } else {
                        button.disabled = false;
                    }
                } catch (error) {
                    button.disabled = false;
                }
            });
        </script>
    @endpush
@endonce
