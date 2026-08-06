<div class="ira-lc-page">
    <div class="ira-lc-page__intro">
        <h1 class="ira-lc-page__title">IRA Learning Center</h1>
        <p class="ira-lc-page__lede">Teach IRA (optional), then dispose every Needs Human email — teaching alone never clears the queue.</p>
    </div>

    @if(session('status'))
        <div class="alert alert-success py-2 mb-3">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger py-2 mb-3">
            <ul class="mb-0 small">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ira-lc-queues">
        @foreach($queues as $queueOption)
            @php
                $count = $counts[$queueOption->value] ?? 0;
            @endphp
            <a href="{{ route('admin.incoming-emails.index', ['queue' => $queueOption->value]) }}"
               @class([
                   'ira-lc-queues__tab',
                   'ira-lc-queues__tab--active' => $queueOption === $queue,
               ])>
                <span>{{ $queueOption->label() }}</span>
                @if($count > 0)
                    <span class="ira-lc-queues__count">{{ number_format($count) }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="ira-lc-page__links">
        <a href="{{ route('admin.gmail.logs') }}">Gmail Sync Logs</a>
        <a href="{{ route('admin.gmail.failed-messages') }}">Failed Messages</a>
        <a href="{{ route('admin.platform.index') }}">Platform</a>
    </div>

    <div class="ira-lc" data-ira-learning-center data-current-queue="{{ $queue->value }}">
        <div class="ira-lc__header">
            <div>
                <h2 class="ira-lc__title">{{ $queue->label() }}</h2>
                <p class="ira-lc__subtitle">Review → Teach (optional) → Disposition (required) → Completed.</p>
            </div>
            <div class="ira-lc__count">{{ number_format(count($cards)) }} shown</div>
        </div>

        @include('admin.incoming-emails.partials.learning-toolbar')

        @if($cards === [])
            <div class="ira-lc__empty">No emails in this queue.</div>
        @else
            <div class="ira-lc-list" data-ira-list>
                <div class="ira-lc-list__head">
                    <div class="ira-lc-row__check"></div>
                    <div>Sender</div>
                    <div>Subject</div>
                    <div>IRA Suggestion</div>
                    <div>Confidence</div>
                    <div>Suggested Owner</div>
                    <div>Received</div>
                    <div></div>
                </div>

                @foreach($cards as $card)
                    @include('admin.incoming-emails.partials.learning-row', ['card' => $card])
                @endforeach
            </div>
        @endif
    </div>
</div>

@once
    @push('scripts')
        @vite('resources/js/ira-learning-center.js')
    @endpush
@endonce
