<div class="card mb-3">
    <div class="card-header py-2"><span class="small fw-semibold">Approvals</span></div>
    <div class="card-body small">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Decision</th>
                    <th>By</th>
                    <th>When</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($approvalTypes as $type)
                    @php
                        $row = $case->hybridApprovals->firstWhere('approval_type', $type);
                    @endphp
                    <tr>
                        <td>{{ $type->label() }}</td>
                        <td>{{ $row?->decision?->label() ?? 'Pending' }}</td>
                        <td>{{ $row?->decider?->name ?? '—' }}</td>
                        <td>{{ $row?->decided_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td>
                            @if ($approvalPermissions[$type->value] ?? false)
                                <form method="POST" action="{{ route('return-exchange-management.approvals.store', $case) }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="approval_type" value="{{ $type->value }}">
                                    <select name="decision" class="form-select form-select-sm" style="max-width: 7rem;">
                                        <option value="approved">Approve</option>
                                        <option value="rejected">Reject</option>
                                        <option value="pending">Reset pending</option>
                                    </select>
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason" required>
                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            @else
                                <span class="text-muted">No permission</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
