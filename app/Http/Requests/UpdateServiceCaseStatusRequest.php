<?php

namespace App\Http\Requests;

use App\Enums\IncidentStatus;
use App\Http\Requests\Concerns\RequiresActionRemarkBody;
use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCaseStatusRequest extends FormRequest
{
    use RequiresActionRemarkBody;

    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return $incident instanceof Incident
            && ($this->user()?->can('update', $incident) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $status = $this->input('status');
        $requiresRemark = in_array($status, [
            IncidentStatus::Resolved->value,
            IncidentStatus::Closed->value,
        ], true);

        return [
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'body' => $requiresRemark
                ? $this->actionRemarkBodyRules()['body']
                : ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status',
            ...$this->actionRemarkBodyAttributes(),
        ];
    }
}
