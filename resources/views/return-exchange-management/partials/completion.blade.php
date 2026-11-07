<div class="card mb-3 border-danger">
    <div class="card-header py-2 bg-danger-subtle"><span class="small fw-semibold">Case completion</span></div>
    <div class="card-body small">
        <p class="mb-2">Sale-row exchange and case completion require Owner-approved orchestration. This button is intentionally blocked.</p>
        <form method="POST" action="{{ route('return-exchange-management.complete', $case) }}">
            @csrf
            <button type="submit" class="btn btn-danger btn-sm" @disabled(! $mayComplete)>Attempt completion</button>
        </form>
    </div>
</div>
