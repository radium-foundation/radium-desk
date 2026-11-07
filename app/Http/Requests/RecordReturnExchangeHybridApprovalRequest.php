<?php

namespace App\Http\Requests;

use App\Enums\ReturnExchangeHybridApprovalDecision;
use App\Enums\ReturnExchangeHybridApprovalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordReturnExchangeHybridApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('return_case');
        $type = ReturnExchangeHybridApprovalType::tryFrom((string) $this->input('approval_type'));

        return $case !== null
            && $type !== null
            && ($this->user()?->can('recordHybridApproval', [$case, $type]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_type' => ['required', Rule::enum(ReturnExchangeHybridApprovalType::class)],
            'decision' => ['required', Rule::enum(ReturnExchangeHybridApprovalDecision::class)],
            'reason' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
