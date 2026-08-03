<?php

namespace App\Http\Requests\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Support\CashBook\CashBookAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCashBookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CashBookAccess::allowsCreate($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CashBookEntryType::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'category' => ['required', 'string', 'max:64'],
            'person' => ['nullable', 'string', 'max:255'],
            'remark' => ['required', 'string', 'max:2000'],
            'entry_date' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->string('type')->toString();
            $category = $this->string('category')->toString();

            if ($type === CashBookEntryType::Income->value) {
                if (! in_array($category, CashBookIncomeSource::values(), true)) {
                    $validator->errors()->add('category', 'Select a valid income source.');
                }
            }

            if ($type === CashBookEntryType::Expense->value) {
                if (! in_array($category, CashBookExpenseCategory::values(), true)) {
                    $validator->errors()->add('category', 'Select a valid expense category.');
                }
            }
        });
    }
}
