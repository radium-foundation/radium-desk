@extends('layouts.app')

@section('title', 'Company Holidays')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Company Holidays</h1>
        <p class="text-muted mb-0">Holidays block automatic assignment. Emergency overrides can be added in a future phase.</p>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Add Holiday</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.workforce.holidays.store') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="holiday_date" class="form-label">Date</label>
                            <input type="date" id="holiday_date" name="holiday_date"
                                   class="form-control @error('holiday_date') is-invalid @enderror"
                                   value="{{ old('holiday_date') }}" required>
                            @error('holiday_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="type" class="form-label">Type</label>
                            <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
                                <option value="">Select type</option>
                                @foreach(\App\Enums\CompanyHolidayType::cases() as $typeOption)
                                    <option value="{{ $typeOption->value }}" @selected(old('type') === $typeOption->value)>
                                        {{ $typeOption->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Add Holiday</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($holidays as $holiday)
                                <tr>
                                    <td>{{ display_app_date($holiday->holiday_date) }}</td>
                                    <td>{{ $holiday->name }}</td>
                                    <td>{{ $holiday->type->label() }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.workforce.holidays.destroy', $holiday) }}"
                                              onsubmit="return confirm('Remove this holiday?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-muted text-center py-4">No holidays configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($holidays->hasPages())
                    <div class="card-footer bg-white">
                        {{ $holidays->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
