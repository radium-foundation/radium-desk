@props([
    'permissionGroups' => [],
    'currentPermissions' => [],
])

@php
    $selectedPermissions = old('permissions', $currentPermissions);
    if (! is_array($selectedPermissions)) {
        $selectedPermissions = $selectedPermissions !== null ? [$selectedPermissions] : [];
    }
@endphp

@if($permissionGroups !== [])
    <div class="col-12">
        <label class="form-label">User Access &amp; Roles</label>
        <div class="border rounded p-3 @error('permissions') is-invalid @enderror @error('permissions.*') is-invalid @enderror">
            @foreach($permissionGroups as $group)
                <div @class(['mb-3' => ! $loop->last])>
                    <div class="fw-semibold small text-uppercase text-muted mb-2">{{ $group['label'] }}</div>
                    @foreach($group['permissions'] as $permission => $label)
                        <div class="form-check">
                            <input type="checkbox"
                                   name="permissions[]"
                                   id="permission_{{ str_replace('.', '_', $permission) }}"
                                   value="{{ $permission }}"
                                   class="form-check-input @error('permissions') is-invalid @enderror @error('permissions.*') is-invalid @enderror"
                                   @checked(in_array($permission, $selectedPermissions, true))>
                            <label class="form-check-label" for="permission_{{ str_replace('.', '_', $permission) }}">
                                {{ $label }}
                            </label>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        @error('permissions')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        @error('permissions.*')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
@endif
