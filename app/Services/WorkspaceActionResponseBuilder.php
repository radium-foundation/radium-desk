<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceActionResponse;
use App\Enums\WorkspaceContext;

class WorkspaceActionResponseBuilder
{
    private bool $success = true;

    private string $message = '';

    private ?WorkspaceContext $context = null;

    /** @var array<string, mixed>|null */
    private ?array $toast = null;

    /** @var array<string, mixed>|null */
    private ?array $ui = null;

    /** @var array<string, mixed>|null */
    private ?array $refresh = null;

    /** @var array<string, list<string>>|null */
    private ?array $errors = null;

    /** @var array<string, mixed> */
    private array $extensions = [];

    public function __construct(
        private readonly string $action,
        private readonly int $incidentId,
    ) {}

    public static function make(string $action, int $incidentId): self
    {
        return new self($action, $incidentId);
    }

    public function forContext(WorkspaceContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function success(string $message): self
    {
        $this->success = true;
        $this->message = $message;

        return $this;
    }

    public function failure(string $message): self
    {
        $this->success = false;
        $this->message = $message;

        return $this;
    }

    public function withToast(string $message, string $variant = 'success', bool $show = true): self
    {
        $this->toast = [
            'show' => $show,
            'message' => $message,
            'variant' => $variant,
        ];

        return $this;
    }

    public function withUi(bool $closeWorkspaceHost = true, ?array $redirect = null): self
    {
        $this->ui = [
            'close_workspace_host' => $closeWorkspaceHost,
            'redirect' => $redirect,
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $refresh
     */
    public function withRefresh(array $refresh): self
    {
        $this->refresh = $refresh;

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function withErrors(array $errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    public function withValidationFragment(string $component, string $html, ?string $target = null): self
    {
        $this->refresh ??= [
            'kpis' => false,
            'targets' => [],
            'fragments' => [],
        ];

        $this->refresh['fragments'][] = [
            'component' => $component,
            'target' => $target ?? (string) config('workspace.targets.workspace_modal_content'),
            'html' => $html,
            'strategy' => 'innerHTML',
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    public function withExtensions(array $extensions): self
    {
        $this->extensions = $extensions;

        return $this;
    }

    public function build(): WorkspaceActionResponse
    {
        return new WorkspaceActionResponse(
            success: $this->success,
            message: $this->message,
            action: $this->action,
            incidentId: $this->incidentId,
            toast: $this->toast,
            ui: $this->ui,
            refresh: $this->refresh,
            errors: $this->errors,
            meta: array_filter([
                'context' => $this->context?->value,
                'workspace_component' => $this->action,
            ]),
            extensions: $this->extensions,
        );
    }

    public function json(int $status = 200): JsonResponse
    {
        return $this->build()->toJsonResponse($status);
    }
}
