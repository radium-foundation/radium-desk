<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Timeline\Customer360TimelineSourceRegistry;
use App\Services\Timeline\Factories\ClassTimelineSourceFactory;
use App\Services\Timeline\Sources\CustomerDataCorrectionTimelineEventSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360TimelineSourceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_resolves_registered_sources_without_switch_statements(): void
    {
        $registry = new Customer360TimelineSourceRegistry([
            new ClassTimelineSourceFactory($this->app, CustomerDataCorrectionTimelineEventSource::class),
        ]);

        $order = Order::factory()->create();
        $sources = $registry->sourcesForOrder($order);

        $this->assertCount(1, $sources);
        $this->assertInstanceOf(CustomerDataCorrectionTimelineEventSource::class, $sources[0]);
    }
}
