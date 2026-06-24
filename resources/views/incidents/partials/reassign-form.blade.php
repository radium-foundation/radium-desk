@can('reassign', $incident)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Reassign Owner</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('incidents.assignment.update', $incident) }}" class="row g-2 align-items-end">
                @csrf
                @method('PATCH')
                <div class="col-sm-8">
                    <label for="assigned_to_user_id" class="form-label">Assigned To</label>
                    <select name="assigned_to_user_id"
                            id="assigned_to_user_id"
                            class="form-select @error('assigned_to_user_id') is-invalid @enderror"
                            required>
                        <option value="" disabled @selected(old('assigned_to_user_id') === null && $incident->assigned_to_user_id === null)>Select admin</option>
                        @foreach($reassignableAdmins as $adminUser)
                            <option value="{{ $adminUser->id }}"
                                @selected((int) old('assigned_to_user_id', $incident->assigned_to_user_id) === $adminUser->id)>
                                {{ $adminUser->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('assigned_to_user_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-sm-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-person-check me-1"></i> Reassign
                    </button>
                </div>
            </form>
        </div>
    </div>
@endcan
