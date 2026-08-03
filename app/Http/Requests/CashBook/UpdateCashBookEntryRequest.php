<?php

namespace App\Http\Requests\CashBook;

use App\Enums\CashBookEntryType;
use App\Enums\CashBookExpenseCategory;
use App\Enums\CashBookIncomeSource;
use App\Models\CashBookEntry;
use App\Support\CashBook\CashBookAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCashBookEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CashBookEntry $entry */
        $entry = $this->route('cashBookEntry');

        return CashBookAccess::allowsManage($this->user())
            && $this->user()?->can('update', $entry);
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
            'backdate_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

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

            try {
                CashBookAccess::assertEntryDateAllowed(
                    $this->user(),
                    $this->string('entry_date')->toString(),
                    $this->input('backdate_reason'),
                );
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
