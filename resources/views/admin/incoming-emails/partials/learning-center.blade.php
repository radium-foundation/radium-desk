@php
    use App\Models\SystemSetting;
    use Illuminate\Support\Facades\Gate;

    $canManageEmailIntake = $canManageEmailIntake ?? false;
    $canViewGmailAdmin = Gate::check('update', SystemSetting::class);
@endphp

<div class="ira-lc-page">
    @include('navigation.administration-workspace-nav', ['active' => 'learning_center'])

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
               title="{{ $queueOption->tooltip() }}"
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

    @if($queue === \App\Enums\IncomingEmailIntakeQueue::Automatic && !empty($automaticBreakdown))
        <div class="ira-lc-subqueues" aria-label="Completed Automatically breakdown">
            <a href="{{ route('admin.incoming-emails.index', ['queue' => 'automatic']) }}"
               @class([
                   'ira-lc-subqueues__tab',
                   'ira-lc-subqueues__tab--active' => empty($subcategory),
               ])>
                <span>All</span>
            </a>
            @foreach($automaticBreakdown as $item)
                <a href="{{ $item['url'] }}"
                   title="{{ $item['tooltip'] }}"
                   @class([
                       'ira-lc-subqueues__tab',
                       'ira-lc-subqueues__tab--active' => !empty($item['active']),
                   ])>
                    <span>{{ $item['label'] }}</span>
                    @if(($item['count'] ?? 0) > 0)
                        <span class="ira-lc-subqueues__count">{{ number_format($item['count']) }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    @if($canViewGmailAdmin)
        <div class="ira-lc-page__links">
            <a href="{{ route('admin.gmail.logs') }}">Gmail Sync Logs</a>
            <a href="{{ route('admin.gmail.failed-messages') }}">Failed Messages</a>
            @can('platform-dashboard.view')
                <a href="{{ route('admin.platform.index') }}">Platform</a>
            @endcan
        </div>
    @endif

    <div class="ira-lc" data-ira-learning-center data-current-queue="{{ $queue->value }}">
        <div class="ira-lc__header">
            <div>
                <h2 class="ira-lc__title">
                    {{ $queue->label() }}
                    @if(!empty($subcategory))
                        <span class="ira-lc__title-sub">· {{ $subcategory->label() }}</span>
                    @endif
                </h2>
                <p class="ira-lc__subtitle">
                    @if($queue === \App\Enums\IncomingEmailIntakeQueue::Automatic)
                        Grouped by how IRA completed the email — routing unchanged.
                    @elseif($queue === \App\Enums\IncomingEmailIntakeQueue::ReviewSuggested)
                        IRA is uncertain — still in Needs Human for routing; this view focuses review.
                    @else
                        Review → Teach (optional) → Disposition (required) → Completed.
                    @endif
                </p>
            </div>
            <div class="ira-lc__count">{{ number_format(count($cards)) }} shown</div>
        </div>

        @if($canManageEmailIntake)
            @include('admin.incoming-emails.partials.learning-toolbar')
        @endif

        @if($cards === [])
            <div class="ira-lc__empty">
                @if($queue === \App\Enums\IncomingEmailIntakeQueue::NeedsHuman)
                    Nothing waiting for a human decision.
                @elseif($queue === \App\Enums\IncomingEmailIntakeQueue::ReviewSuggested)
                    No emails where IRA is uncertain right now.
                @elseif($queue === \App\Enums\IncomingEmailIntakeQueue::Automatic)
                    No emails completed automatically in this view.
                @elseif($queue === \App\Enums\IncomingEmailIntakeQueue::Spam)
                    Spam queue is clear.
                @else
                    No emails in this queue.
                @endif
            </div>
        @else
            <div class="ira-lc-list" data-ira-list>
                <div class="ira-lc-list__head">
                    <div class="ira-lc-row__check"></div>
                    <div>Sender</div>
                    <div>Subject</div>
                    @if($queue === \App\Enums\IncomingEmailIntakeQueue::Automatic)
                        <div>Result</div>
                        <div>Handled By</div>
                        <div></div>
                    @else
                        <div>IRA Suggestion</div>
                        <div>Confidence</div>
                        <div>Suggested Owner</div>
                    @endif
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
