<?php

namespace Tests\Unit\ConversationWorkspace;

use App\Models\Incident;
use App\Models\Order;
use App\Services\ConversationWorkspace\ConversationWorkspaceModeResolver;
use Tests\TestCase;

class ConversationWorkspaceModeResolverTest extends TestCase
{
    public function test_disabled_by_default(): void
    {
        config(['conversation_workspace.enabled' => false]);

        $resolver = new ConversationWorkspaceModeResolver;
        $incident = new Incident;
        $order = new Order(['order_id' => 'INQ-SC00001']);

        $this->assertFalse($resolver->isActive($incident, $order, [
            'live_incoming_call' => true,
        ]));
    }

    public function test_requires_live_incoming_call(): void
    {
        config(['conversation_workspace.enabled' => true]);

        $resolver = new ConversationWorkspaceModeResolver;
        $incident = new Incident;
        $order = new Order(['order_id' => 'INQ-SC00001']);

        $this->assertFalse($resolver->isActive($incident, $order, []));
        $this->assertTrue($resolver->isActive($incident, $order, [
            'live_incoming_call' => true,
        ]));
    }

    public function test_rejects_non_inquiry_orders(): void
    {
        config(['conversation_workspace.enabled' => true]);

        $resolver = new ConversationWorkspaceModeResolver;
        $incident = new Incident;
        $order = new Order(['order_id' => 'RD-12345']);

        $this->assertFalse($resolver->isActive($incident, $order, [
            'live_incoming_call' => true,
        ]));
    }

    public function test_rejects_already_linked_inquiry(): void
    {
        config(['conversation_workspace.enabled' => true]);

        $resolver = new ConversationWorkspaceModeResolver;
        $incident = new Incident(['inquiry_origin_order_id' => 99]);
        $order = new Order(['order_id' => 'INQ-SC00001']);

        $this->assertFalse($resolver->isActive($incident, $order, [
            'live_incoming_call' => true,
        ]));
    }
}
