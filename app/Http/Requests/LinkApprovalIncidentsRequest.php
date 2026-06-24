<?php

namespace App\Http\Requests;

use App\Models\ApprovalNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkApprovalIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $approval = $this->route('approval');

        return $approval instanceof ApprovalNumber
            && ($this->user()?->can('approvals.link') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'incident_ids' => ['required', 'array', 'min:1'],
            'incident_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('incidents', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'incident_ids' => 'incidents',
            'incident_ids.*' => 'incident',
        ];
    }
}
