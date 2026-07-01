<?php

namespace App\Http\Requests;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Remark;
use App\Services\WorkspaceRemarkActionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceRemarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Incident $incident */
        $incident = $this->route('incident');
        $user = $this->user();

        if (! $user || ! $user->can('create', Remark::class)) {
            return false;
        }

        return $user->can('view', $incident);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:3', 'max:5000'],
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
            'body' => 'note',
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
            app(WorkspaceRemarkActionService::class)->validationFailure(
                $incident,
                $requestContext,
                ValidationException::withMessages($validator->errors()->messages()),
                $this->input('body'),
            )->toJsonResponse(422),
        );
    }
}
