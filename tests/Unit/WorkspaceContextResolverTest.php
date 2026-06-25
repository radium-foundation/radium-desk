<?php

namespace Tests\Unit;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\WorkspaceContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkspaceContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(WorkspaceContextResolver::class);
    }

    public function test_resolve_uses_query_context_when_present(): void
    {
        $request = Request::create('/incidents/1/components/assign', 'GET', [
            'context' => WorkspaceContext::Dashboard->value,
        ]);

        $context = $this->resolver->resolve($request);

        $this->assertInstanceOf(WorkspaceRequestContext::class, $context);
        $this->assertSame(WorkspaceContext::Dashboard, $context->context);
    }

    public function test_resolve_uses_body_context_when_query_missing(): void
    {
        $request = Request::create('/incidents/1/workspace/assign', 'POST', [
            'workspace_context' => WorkspaceContext::Order->value,
        ]);

        $context = $this->resolver->resolve($request);

        $this->assertSame(WorkspaceContext::Order, $context->context);
    }

    public function test_resolve_uses_header_context_when_query_and_body_missing(): void
    {
        $request = Request::create('/incidents/1/components/remark', 'GET');
        $request->headers->set('X-Workspace-Context', WorkspaceContext::Mobile->value);

        $context = $this->resolver->resolve($request);

        $this->assertSame(WorkspaceContext::Mobile, $context->context);
    }

    public function test_resolve_defaults_to_service_case_when_context_missing(): void
    {
        $request = Request::create('/incidents/1/components/remark', 'GET');

        $context = $this->resolver->resolve($request);

        $this->assertSame(WorkspaceContext::ServiceCase, $context->context);
    }

    public function test_resolve_rejects_invalid_context(): void
    {
        $request = Request::create('/incidents/1/components/remark', 'GET', [
            'context' => 'invalid-context',
        ]);

        $this->expectException(ValidationException::class);

        $this->resolver->resolve($request);
    }

    public function test_resolve_includes_incident_and_order_ids(): void
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'ORD-CTX-1',
            'serial_number' => 'SN-CTX-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-CTX-1',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Context resolver test',
            'description' => 'Context resolver test.',
            'status' => 'open',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $request = Request::create('/incidents/1/components/remark', 'GET', [
            'context' => WorkspaceContext::ServiceCase->value,
        ]);
        $request->headers->set('Referer', 'https://example.test/incidents/1');

        $context = $this->resolver->resolve($request, $incident);

        $this->assertSame($incident->id, $context->incidentId);
        $this->assertSame($order->id, $context->orderId);
        $this->assertSame('https://example.test/incidents/1', $context->sourcePage);
    }

    public function test_resolve_or_null_returns_null_when_missing(): void
    {
        $request = Request::create('/incidents/1/components/remark', 'GET');

        $this->assertNull($this->resolver->resolveOrNull($request));
    }

    public function test_resolve_or_null_returns_enum_when_present(): void
    {
        $request = Request::create('/incidents/1/components/remark', 'GET', [
            'context' => WorkspaceContext::Api->value,
        ]);

        $this->assertSame(WorkspaceContext::Api, $this->resolver->resolveOrNull($request));
    }
}
