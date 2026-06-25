<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">SLA Thresholds</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.sla.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="normal_warning_hours" class="form-label">Normal Warning Hours</label>
                    <input type="number" name="normal_warning_hours" id="normal_warning_hours" class="form-control @error('normal_warning_hours') is-invalid @enderror"
                           value="{{ old('normal_warning_hours', $sla['normal_warning_hours']) }}" min="1" required>
                    @error('normal_warning_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="normal_overdue_hours" class="form-label">Normal Overdue Hours</label>
                    <input type="number" name="normal_overdue_hours" id="normal_overdue_hours" class="form-control @error('normal_overdue_hours') is-invalid @enderror"
                           value="{{ old('normal_overdue_hours', $sla['normal_overdue_hours']) }}" min="1" required>
                    @error('normal_overdue_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="priority_warning_hours" class="form-label">Priority Warning Hours</label>
                    <input type="number" name="priority_warning_hours" id="priority_warning_hours" class="form-control @error('priority_warning_hours') is-invalid @enderror"
                           value="{{ old('priority_warning_hours', $sla['priority_warning_hours']) }}" min="1" required>
                    @error('priority_warning_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="priority_overdue_hours" class="form-label">Priority Overdue Hours</label>
                    <input type="number" name="priority_overdue_hours" id="priority_overdue_hours" class="form-control @error('priority_overdue_hours') is-invalid @enderror"
                           value="{{ old('priority_overdue_hours', $sla['priority_overdue_hours']) }}" min="1" required>
                    @error('priority_overdue_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save SLA Settings</button>
            </div>
        </form>
    </div>
</div>
