@php
    $branch = $branch ?? null;
@endphp
<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">Code</label>
        <input type="text" name="code" class="form-control" required value="{{ old('code', $branch?->code) }}">
        @error('code')<div class="text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-5">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="{{ old('name', $branch?->name) }}">
        @error('name')<div class="text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">GSTIN</label>
        <input type="text" name="gstin" class="form-control" value="{{ old('gstin', $branch?->gstin) }}">
    </div>
    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="branch_active" @checked(old('is_active', $branch?->is_active ?? true))>
            <label class="form-check-label" for="branch_active">Active</label>
        </div>
    </div>
    @if(isset($assignableUsers) && $assignableUsers->isNotEmpty())
        <div class="col-12">
            <label class="form-label">Assigned operators</label>
            <p class="small text-muted mb-2">Users without all-branch access can only sell, transfer, or adjust stock at assigned branches. Administrators with all-branch access are not listed.</p>
            <div class="border rounded p-3">
                @foreach($assignableUsers as $user)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="user_ids[]" value="{{ $user->id }}" id="branch_user_{{ $user->id }}"
                               @checked(in_array($user->id, old('user_ids', $assignedUserIds ?? []), true) || in_array((string) $user->id, old('user_ids', []), true))>
                        <label class="form-check-label" for="branch_user_{{ $user->id }}">
                            {{ $user->name }} <span class="text-muted">({{ $user->email }})</span>
                        </label>
                    </div>
                @endforeach
            </div>
            @error('user_ids')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>
    @endif
</div>
