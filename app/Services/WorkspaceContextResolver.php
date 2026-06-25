<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceContextResolver
{
    public function resolve(Request $request, ?Incident $incident = null): WorkspaceRequestContext
    {
        $raw = $this->extractRawContext($request);

        if ($raw === null || $raw === '') {
            $context = WorkspaceContext::from((string) config('workspace.default'));
        } else {
            $context = WorkspaceContext::tryFrom($raw);

            if ($context === null) {
                throw ValidationException::withMessages([
                    (string) config('workspace.keys.body') => ['The workspace context is invalid.'],
                ]);
            }
        }

        $orderId = $request->filled('order_id')
            ? $request->integer('order_id')
            : $incident?->order_id;

        return new WorkspaceRequestContext(
            context: $context,
            incidentId: $incident?->id,
            orderId: $orderId,
            sourcePage: $request->headers->get('Referer'),
        );
    }

    public function resolveOrNull(Request $request): ?WorkspaceContext
    {
        $raw = $this->extractRawContext($request);

        if ($raw === null || $raw === '') {
            return null;
        }

        return WorkspaceContext::tryFrom($raw);
    }

    private function extractRawContext(Request $request): ?string
    {
        $keys = config('workspace.keys');

        $raw = $request->query($keys['query'])
            ?? $request->input($keys['body'])
            ?? $request->header($keys['header']);

        if ($raw === null) {
            return null;
        }

        $value = is_string($raw) ? trim($raw) : null;

        return $value === '' ? null : $value;
    }
}
