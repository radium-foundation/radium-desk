@props(['incident', 'linkableApprovals' => collect()])

@php
    $canViewApprovals = auth()->user()?->can('approvals.view') ?? false;
    $canCreateApproval = auth()->user()?->can('approvals.create') ?? false;
    $canLinkApproval = auth()->user()?->can('approvals.link') ?? false;
    $hasApprovals = $incident->approvalNumbers->isNotEmpty();
    $showApprovalSection = $canViewApprovals && ($hasApprovals || $canCreateApproval || $canLinkApproval);
@endphp

@if($showApprovalSection)
    <div class="mb-3 @if($incident->refundRequests->isNotEmpty()) pb-3 border-bottom @endif">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h3 class="h6 text-muted small text-uppercase mb-0">Approval Numbers</h3>
            @can('viewAny', App\Models\ApprovalNumber::class)
                <a href="{{ route('approvals.index') }}" class="small text-decoration-none">
                    View all
                </a>
            @endcan
        </div>

        @if($hasApprovals)
            <ul class="list-unstyled mb-3">
                @foreach($incident->approvalNumbers as $approval)
                    <li class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <a href="{{ route('approvals.show', $approval) }}" class="text-decoration-none fw-semibold">
                                {{ $approval->approval_number }}
                            </a>
                            @if($approval->description)
                                <span class="text-muted small">— {{ $approval->description }}</span>
                            @endif
                        </div>
                        @can('link', $approval)
                            <form method="POST"
                                  action="{{ route('approvals.incidents.unlink', [$approval, $incident]) }}"
                                  onsubmit="return confirm('Remove this approval number from the service case?');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="return_incident" value="{{ $incident->id }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Unlink approval">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-muted small mb-3">No approval numbers linked.</p>
        @endif

        @if($canCreateApproval)
            <div class="border rounded p-3 mb-3 bg-light-subtle">
                <h4 class="h6 mb-2">Create approval</h4>
                <form method="POST" action="{{ route('approvals.store') }}">
                    @csrf
                    <input type="hidden" name="incident_id" value="{{ $incident->id }}">
                    <input type="hidden" name="return_incident" value="{{ $incident->id }}">
                    <div class="mb-2">
                        <label for="approval_description_{{ $incident->id }}" class="form-label small mb-1">Description</label>
                        <textarea name="description"
                                  id="approval_description_{{ $incident->id }}"
                                  class="form-control form-control-sm"
                                  rows="2"
                                  maxlength="2000"
                                  placeholder="Optional description"></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Create &amp; Link
                    </button>
                </form>
            </div>
        @endif

        @if($canLinkApproval && $linkableApprovals->isNotEmpty())
            <div class="border rounded p-3 bg-light-subtle">
                <h4 class="h6 mb-2">Link existing approval</h4>
                <form method="POST"
                      id="link_existing_approval_form_{{ $incident->id }}"
                      action="{{ route('approvals.incidents.link', $linkableApprovals->first()) }}">
                    @csrf
                    <input type="hidden" name="incident_ids[]" value="{{ $incident->id }}">
                    <input type="hidden" name="return_incident" value="{{ $incident->id }}">
                    <div class="mb-2">
                        <label for="link_approval_id_{{ $incident->id }}" class="form-label small mb-1">Approval number</label>
                        <select name="approval_selector"
                                id="link_approval_id_{{ $incident->id }}"
                                class="form-select form-select-sm"
                                required
                                data-link-form="link_existing_approval_form_{{ $incident->id }}"
                                data-link-template="{{ url('approvals') }}/__APPROVAL__/incidents">
                            <option value="" disabled selected>Select an approval number</option>
                            @foreach($linkableApprovals as $approval)
                                <option value="{{ $approval->id }}">
                                    {{ $approval->approval_number }}
                                    @if($approval->description)
                                        — {{ Str::limit($approval->description, 40) }}
                                    @endif
                                    ({{ $approval->incidents_count }}/{{ \App\Models\ApprovalNumber::MAX_INCIDENTS }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-link-45deg me-1"></i> Link approval
                    </button>
                </form>
            </div>
        @elseif($canLinkApproval && ! $hasApprovals)
            <p class="text-muted small mb-0">No available approval numbers to link.</p>
        @endif
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('[data-link-form]').forEach((select) => {
                    const form = document.getElementById(select.dataset.linkForm);
                    const template = select.dataset.linkTemplate;

                    if (!form || !template) {
                        return;
                    }

                    select.addEventListener('change', () => {
                        if (!select.value) {
                            return;
                        }

                        form.action = template.replace('__APPROVAL__', select.value);
                    });
                });
            });
        </script>
    @endpush
@endif
