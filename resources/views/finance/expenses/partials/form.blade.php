@php
    /** @var \App\Models\FinanceExpense|null $expense */
    $expense = $expense ?? null;
    $accountType = old(
        'account_type',
        $expense?->cash_account_id ? 'cash' : ($expense?->bank_account_id ? 'bank' : 'cash')
    );
@endphp

<div class="row g-3">
    <div class="col-md-4">
        <label for="expense_date" class="form-label">Expense date</label>
        <input
            type="date"
            id="expense_date"
            name="expense_date"
            class="form-control @error('expense_date') is-invalid @enderror"
            value="{{ old('expense_date', optional($expense?->expense_date)->format('Y-m-d') ?? now()->toDateString()) }}"
            required
        >
        @error('expense_date')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="expense_category_id" class="form-label">Category</label>
        <select
            id="expense_category_id"
            name="expense_category_id"
            class="form-select @error('expense_category_id') is-invalid @enderror"
            required
        >
            <option value="">Select category</option>
            @foreach($categories as $category)
                <option
                    value="{{ $category->id }}"
                    @selected((string) old('expense_category_id', $expense?->expense_category_id) === (string) $category->id)
                >
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        @error('expense_category_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="amount" class="form-label">Amount</label>
        <input
            type="number"
            id="amount"
            name="amount"
            class="form-control @error('amount') is-invalid @enderror"
            value="{{ old('amount', $expense?->amount) }}"
            min="0.01"
            step="0.01"
            required
        >
        @error('amount')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="payment_method_id" class="form-label">Payment method</label>
        <select
            id="payment_method_id"
            name="payment_method_id"
            class="form-select @error('payment_method_id') is-invalid @enderror"
            required
        >
            <option value="">Select method</option>
            @foreach($paymentMethods as $method)
                <option
                    value="{{ $method->id }}"
                    @selected((string) old('payment_method_id', $expense?->payment_method_id) === (string) $method->id)
                >
                    {{ $method->name }}
                </option>
            @endforeach
        </select>
        @error('payment_method_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="account_type" class="form-label">Paid from</label>
        <select
            id="account_type"
            name="account_type"
            class="form-select @error('account_type') is-invalid @enderror"
            required
            data-finance-account-type
        >
            <option value="cash" @selected($accountType === 'cash')>Cash account</option>
            <option value="bank" @selected($accountType === 'bank')>Bank account</option>
        </select>
        @error('account_type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4" data-finance-cash-account @style(['display: none' => $accountType !== 'cash'])>
        <label for="cash_account_id" class="form-label">Cash account</label>
        <select
            id="cash_account_id"
            name="cash_account_id"
            class="form-select @error('cash_account_id') is-invalid @enderror"
        >
            <option value="">Select cash account</option>
            @foreach($cashAccounts as $cashAccount)
                <option
                    value="{{ $cashAccount->id }}"
                    @selected((string) old('cash_account_id', $expense?->cash_account_id) === (string) $cashAccount->id)
                >
                    {{ $cashAccount->name }}
                </option>
            @endforeach
        </select>
        @error('cash_account_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4" data-finance-bank-account @style(['display: none' => $accountType !== 'bank'])>
        <label for="bank_account_id" class="form-label">Bank account</label>
        <select
            id="bank_account_id"
            name="bank_account_id"
            class="form-select @error('bank_account_id') is-invalid @enderror"
        >
            <option value="">Select bank account</option>
            @foreach($bankAccounts as $bankAccount)
                <option
                    value="{{ $bankAccount->id }}"
                    @selected((string) old('bank_account_id', $expense?->bank_account_id) === (string) $bankAccount->id)
                >
                    {{ $bankAccount->bank_name }} · {{ $bankAccount->account_name }} ({{ $bankAccount->last_four }})
                </option>
            @endforeach
        </select>
        @error('bank_account_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="description" class="form-label">Description</label>
        <textarea
            id="description"
            name="description"
            rows="3"
            class="form-control @error('description') is-invalid @enderror"
            required
            maxlength="2000"
        >{{ old('description', $expense?->description) }}</textarea>
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="receipt" class="form-label">Receipt (optional)</label>
        <input
            type="file"
            id="receipt"
            name="receipt"
            class="form-control @error('receipt') is-invalid @enderror"
            accept=".pdf,.jpg,.jpeg,.png"
        >
        @error('receipt')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">PDF or image, max 5 MB.@if($expense?->receipt_path) Uploading a new file replaces the current receipt.@endif</div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            (() => {
                const typeSelect = document.querySelector('[data-finance-account-type]');
                const cashBlock = document.querySelector('[data-finance-cash-account]');
                const bankBlock = document.querySelector('[data-finance-bank-account]');
                if (!typeSelect || !cashBlock || !bankBlock) return;

                const sync = () => {
                    const isCash = typeSelect.value === 'cash';
                    cashBlock.style.display = isCash ? '' : 'none';
                    bankBlock.style.display = isCash ? 'none' : '';
                };

                typeSelect.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endpush
@endonce
