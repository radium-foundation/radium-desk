@extends('layouts.app')

@section('title', 'Salaries')

@section('content')
    <div class="workforce-management-page" data-workforce-salaries>
        <header class="wm-page-header">
            <div class="wm-page-header__eyebrow">Workforce Management</div>
            <h1 class="wm-page-header__title">Salaries</h1>
            <p class="wm-page-header__subtitle">Append-only salary revisions · historical rows are never overwritten</p>
        </header>

        @include('workforce-management.partials.workspace-nav', ['active' => 'salaries'])

        <section class="wm-matrix-panel mb-4">
            <div class="wm-matrix-panel__meta">
                <div class="wm-matrix-panel__note">
                    Add a new revision to change pay. Payroll uses the latest active revision with Effective From on or before the month end.
                </div>
            </div>
            <div class="p-3">
                <form method="POST" action="{{ route('workforce-management.salaries.store') }}" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-md-4">
                        <label for="salary-user" class="form-label">Employee</label>
                        <select id="salary-user" name="user_id" class="form-select" required>
                            <option value="">Select employee</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('user_id') === (string) $employee->id)>{{ $employee->name }}</option>
                            @endforeach
                        </select>
                        @error('user_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="salary-amount" class="form-label">Monthly Salary</label>
                        <input id="salary-amount" type="number" step="0.01" min="0" name="monthly_salary" class="form-control" value="{{ old('monthly_salary') }}" required>
                        @error('monthly_salary') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="salary-effective" class="form-label">Effective From</label>
                        <input id="salary-effective" type="date" name="effective_from" class="form-control" value="{{ old('effective_from', now()->toDateString()) }}" required>
                        @error('effective_from') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="salary-active" value="1" @checked(old('is_active', true))>
                            <label class="form-check-label" for="salary-active">Active</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add revision</button>
                    </div>
                </form>
            </div>
        </section>

        <div class="wm-matrix-panel">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="text-end">Monthly Salary</th>
                            <th>Effective From</th>
                            <th>Active</th>
                            <th>New revision</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($salaries as $salary)
                            <tr>
                                <td>{{ $salary->user?->name ?? '—' }}</td>
                                <td class="text-end">₹{{ number_format((float) $salary->monthly_salary, 2) }}</td>
                                <td>{{ $salary->effective_from?->toDateString() }}</td>
                                <td>{{ $salary->is_active ? 'Yes' : 'No' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('workforce-management.salaries.revise', $salary) }}" class="row g-2 align-items-center">
                                        @csrf
                                        <div class="col-auto">
                                            <input type="number" step="0.01" min="0" name="monthly_salary" class="form-control form-control-sm" value="{{ $salary->monthly_salary }}" required aria-label="New monthly salary">
                                        </div>
                                        <div class="col-auto">
                                            <input type="date" name="effective_from" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required aria-label="New effective from">
                                        </div>
                                        <div class="col-auto">
                                            <input type="hidden" name="is_active" value="0">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="rev-active-{{ $salary->id }}" checked>
                                                <label class="form-check-label" for="rev-active-{{ $salary->id }}">Active</label>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Add revision</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-muted text-center py-4">No salary revisions yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
