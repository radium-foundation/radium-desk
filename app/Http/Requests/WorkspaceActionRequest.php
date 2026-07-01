<?php

namespace App\Http\Requests;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceContext;
use App\Http\Requests\Concerns\RequiresActionRemarkBody;
use App\Models\Incident;
use App\Services\WorkspaceActionDialogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceActionRequest extends FormRequest
{
    use RequiresActionRemarkBody;

    public function authorize(): bool
    {
        /** @var Incident $incident */
        $incident = $this->route('incident');
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $actionType = WorkspaceActionType::tryFrom((string) $this->input('action_type'));

        if ($actionType === null) {
            return false;
        }

        return match ($actionType) {
            WorkspaceActionType::Assign => $user->can('reassign', $incident)
                && $incident->status !== IncidentStatus::Closed,
            WorkspaceActionType::Close => $user->can('update', $incident)
                && $incident->status !== IncidentStatus::Closed,
            WorkspaceActionType::Reopen => $user->can('update', $incident)
                && $incident->status === IncidentStatus::Closed,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $actionType = WorkspaceActionType::tryFrom((string) $this->input('action_type'));

        $rules = [
            'action_type' => ['required', 'string', Rule::in(WorkspaceActionType::values())],
            'workspace_context' => ['required', 'string', Rule::in(WorkspaceContext::values())],
            ...$this->actionRemarkBodyRules(),
        ];

        return match ($actionType) {
            WorkspaceActionType::Assign => [
                ...$rules,
                'assigned_to_user_id' => [
                    'required',
                    'integer',
                    Rule::exists('users', 'id')->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at')),
                ],
            ],
            WorkspaceActionType::Close => [
                ...$rules,
                'serial_number_unavailable' => ['sometimes', 'boolean'],
                'reference_number_unavailable' => ['sometimes', 'boolean'],
                'exception_reason' => [
                    Rule::requiredIf(fn (): bool => $this->boolean('serial_number_unavailable')
                        || $this->boolean('reference_number_unavailable')),
                    'nullable',
                    'string',
                    Rule::in(ServiceCaseCloseExceptionReason::values()),
                ],
                'exception_reason_custom' => [
                    Rule::requiredIf(fn (): bool => $this->input('exception_reason') === ServiceCaseCloseExceptionReason::Other->value),
                    'nullable',
                    'string',
                    'max:5000',
                ],
                'notify_whatsapp' => ['sometimes', 'boolean'],
                'notify_email' => ['sometimes', 'boolean'],
            ],
            WorkspaceActionType::Reopen => [
                ...$rules,
                'reopen_reason' => ['required', 'string', 'max:5000'],
                'assigned_to_user_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('users', 'id')->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at')),
                ],
            ],
            default => $rules,
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...$this->actionRemarkBodyAttributes(),
            'action_type' => 'action',
            'assigned_to_user_id' => 'assign to',
            'workspace_context' => 'workspace context',
            'exception_reason' => 'exception reason',
            'exception_reason_custom' => 'custom exception remark',
            'reopen_reason' => 'reason',
            'serial_number_unavailable' => 'serial number unavailable',
            'reference_number_unavailable' => 'reference number unavailable',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge([
                'body' => trim((string) $this->input('body')),
            ]);
        }

        $this->merge([
            'serial_number_unavailable' => $this->boolean('serial_number_unavailable'),
            'reference_number_unavailable' => $this->boolean('reference_number_unavailable'),
            'notify_whatsapp' => $this->boolean('notify_whatsapp'),
            'notify_email' => $this->boolean('notify_email'),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var Incident $incident */
        $incident = $this->route('incident');

        $rawContext = $this->input('workspace_context');
        $context = is_string($rawContext)
            ? (WorkspaceContext::tryFrom($rawContext) ?? WorkspaceContext::from((string) config('workspace.default')))
            : WorkspaceContext::from((string) config('workspace.default'));

        $requestContext = new WorkspaceRequestContext(
            context: $context,
            incidentId: $incident->id,
            orderId: $incident->order_id,
            sourcePage: $this->headers->get('Referer'),
        );

        $actionType = WorkspaceActionType::tryFrom((string) $this->input('action_type'))
            ?? WorkspaceActionType::Assign;

        throw new HttpResponseException(
            app(WorkspaceActionDialogService::class)->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages($validator->errors()->messages()),
                $actionType,
                $this->all(),
            )->toJsonResponse(422),
        );
    }
}
