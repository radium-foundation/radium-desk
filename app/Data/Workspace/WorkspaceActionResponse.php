<?php

namespace App\Data\Workspace;

use Illuminate\Http\JsonResponse;

readonly class WorkspaceActionResponse
{
    /**
     * @param  array<string, mixed>|null  $toast
     * @param  array<string, mixed>|null  $ui
     * @param  array<string, mixed>|null  $refresh
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public bool $success,
        public string $message,
        public string $action,
        public int $incidentId,
        public int $contractVersion = 1,
        public ?array $toast = null,
        public ?array $ui = null,
        public ?array $refresh = null,
        public ?array $errors = null,
        public array $meta = [],
        public array $extensions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_version' => $this->contractVersion,
            'success' => $this->success,
            'message' => $this->message,
            'action' => $this->action,
            'incident_id' => $this->incidentId,
            'toast' => $this->toast,
            'ui' => $this->ui,
            'refresh' => $this->refresh,
            'errors' => $this->errors,
            'meta' => $this->meta,
            'extensions' => $this->extensions,
        ];
    }

    public function toJsonResponse(int $status = 200): JsonResponse
    {
        return response()->json($this->toArray(), $status);
    }
}
