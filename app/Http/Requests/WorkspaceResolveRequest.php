<?php

namespace App\Http\Requests;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\IncidentStatus;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Services\WorkspaceResolveActionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Incident $incident */
        $incident = $this->route('incident');

        return ($this->user()?->can('update', $incident) ?? false)
            && ! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_context' => [
                'required',
                'string',
                Rule::in(WorkspaceContext::values()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'workspace_context' => 'workspace context',
        ];
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

        throw new HttpResponseException(
            app(WorkspaceResolveActionService::class)->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages($validator->errors()->messages()),
            )->toJsonResponse(422),
        );
    }
}
