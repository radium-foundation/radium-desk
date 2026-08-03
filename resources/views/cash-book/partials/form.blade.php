@php
    $selectedType = $type ?? old('type', 'income');
@endphp

<div class="mb-3">
    <label class="form-label fw-semibold mb-2">Type <span class="text-danger">*</span></label>
    <div class="d-flex flex-wrap gap-3">
        <div class="form-check">
            <input
                class="form-check-input"
                type="radio"
                name="type"
                id="type-income"
                value="income"
                @checked($selectedType === 'income')
                required
            >
            <label class="form-check-label" for="type-income">Income</label>
        </div>
        <div class="form-check">
            <input
                class="form-check-input"
                type="radio"
                name="type"
                id="type-expense"
                value="expense"
                @checked($selectedType === 'expense')
                required
            >
            <label class="form-check-label" for="type-expense">Expense</label>
        </div>
    </div>
    @error('type')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
</div>

<div class="row g-3">
    <div class="col-md-4">
        <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
        <input
            type="number"
            step="0.01"
            min="0.01"
            name="amount"
            id="amount"
            class="form-control @error('amount') is-invalid @enderror"
            value="{{ $amount ?? old('amount') }}"
            required
            autofocus
        >
        @error('amount')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4" id="income-source-wrap">
        <label for="income_source" class="form-label">Income Source <span class="text-danger">*</span></label>
        <select id="income_source" class="form-select">
            <option value="">Select income source</option>
            @foreach ($incomeSources as $source)
                <option
                    value="{{ $source->value }}"
                    @selected(($category ?? old('category')) === $source->value && $selectedType === 'income')
                >
                    {{ $source->label() }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-md-4 d-none" id="expense-category-wrap">
        <label for="expense_category" class="form-label">Expense Category <span class="text-danger">*</span></label>
        <select id="expense_category" class="form-select">
            <option value="">Select expense category</option>
            @foreach ($expenseCategories as $expenseCategory)
                <option
                    value="{{ $expenseCategory->value }}"
                    @selected(($category ?? old('category')) === $expenseCategory->value && $selectedType === 'expense')
                >
                    {{ $expenseCategory->label() }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-md-4">
        <label for="person" class="form-label" id="person-label">
            {{ $selectedType === 'expense' ? 'Paid To' : 'Received From' }}
        </label>
        <input
            type="text"
            name="person"
            id="person"
            class="form-control @error('person') is-invalid @enderror"
            value="{{ $person ?? old('person') }}"
            maxlength="255"
            placeholder="{{ $selectedType === 'expense' ? 'Blue Dart, Tea Stall, Porter…' : 'Customer Name, Walk-in Customer…' }}"
        >
        <div class="form-text">Optional</div>
        @error('person')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-8">
        <label for="remark" class="form-label">Remark <span class="text-danger">*</span></label>
        <input
            type="text"
            name="remark"
            id="remark"
            class="form-control @error('remark') is-invalid @enderror"
            value="{{ $remark ?? old('remark') }}"
            maxlength="2000"
            required
            placeholder="{{ $selectedType === 'expense' ? 'Lysol, Coolie, Rickshaw…' : 'Sold old MFS110, Invoice 125, RD3470012…' }}"
        >
        @error('remark')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="entry_date" class="form-label">Date</label>
        <input
            type="date"
            name="entry_date"
            id="entry_date"
            class="form-control @error('entry_date') is-invalid @enderror"
            value="{{ $entry_date ?? old('entry_date', now()->toDateString()) }}"
            required
        >
        @error('entry_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<input type="hidden" name="category" id="category" value="{{ $category ?? old('category') }}">

@error('category')
    <div class="text-danger small mt-2">{{ $message }}</div>
@enderror

@push('scripts')
<script>
(() => {
    const incomeRadio = document.getElementById('type-income');
    const expenseRadio = document.getElementById('type-expense');
    const incomeWrap = document.getElementById('income-source-wrap');
    const expenseWrap = document.getElementById('expense-category-wrap');
    const incomeSelect = document.getElementById('income_source');
    const expenseSelect = document.getElementById('expense_category');
    const categoryInput = document.getElementById('category');
    const remarkInput = document.getElementById('remark');
    const personInput = document.getElementById('person');
    const personLabel = document.getElementById('person-label');

    function syncType() {
        const isIncome = incomeRadio.checked;
        incomeWrap.classList.toggle('d-none', !isIncome);
        expenseWrap.classList.toggle('d-none', isIncome);

        if (isIncome) {
            categoryInput.value = incomeSelect.value;
            remarkInput.placeholder = 'Sold old MFS110, Invoice 125, RD3470012…';
            personInput.placeholder = 'Customer Name, Walk-in Customer…';
            if (personLabel) personLabel.textContent = 'Received From';
        } else {
            categoryInput.value = expenseSelect.value;
            remarkInput.placeholder = 'Lysol, Coolie, Rickshaw…';
            personInput.placeholder = 'Blue Dart, Tea Stall, Porter…';
            if (personLabel) personLabel.textContent = 'Paid To';
        }
    }

    incomeSelect?.addEventListener('change', () => {
        if (incomeRadio.checked) categoryInput.value = incomeSelect.value;
    });
    expenseSelect?.addEventListener('change', () => {
        if (expenseRadio.checked) categoryInput.value = expenseSelect.value;
    });
    incomeRadio?.addEventListener('change', syncType);
    expenseRadio?.addEventListener('change', syncType);
    syncType();
})();
</script>
@endpush
