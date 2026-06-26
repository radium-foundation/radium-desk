<?php

namespace App\Http\Requests;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceContext;
use App\Services\WorkspaceBatchTransactionActionService;
use App\Services\WorkspaceContextResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceBatchTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'incident_ids' => ['required', 'array', 'min:1'],
            'incident_ids.*' => ['required', 'integer', 'exists:incidents,id'],
            'transaction_id' => ['required', 'string', 'max:100'],
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
            'transaction_id' => 'transaction ID',
            'incident_ids' => 'service cases',
            'workspace_context' => 'workspace context',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $incidentIds = array_map('intval', $this->input('incident_ids', []));
        $requestContext = app(WorkspaceContextResolver::class)->resolve($this);

        throw new HttpResponseException(
            app(WorkspaceBatchTransactionActionService::class)->validationFailure(
                $incidentIds,
                $requestContext,
                ValidationException::withMessages($validator->errors()->messages()),
            )->toJsonResponse(422),
        );
    }
}
