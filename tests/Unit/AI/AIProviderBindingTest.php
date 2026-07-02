<?php

namespace Tests\Unit\AI;

use App\Contracts\AI\AIProvider;
use App\Data\AI\AIContextDTO;
use App\Data\AI\AIRecommendationDTO;
use App\Services\AI\Providers\NullAIProvider;
use Tests\TestCase;

class AIProviderBindingTest extends TestCase
{
    public function test_null_provider_is_bound_by_default(): void
    {
        config(['ai.provider' => 'null']);

        $provider = app(AIProvider::class);

        $this->assertInstanceOf(NullAIProvider::class, $provider);
        $this->assertSame('null', $provider->name());
    }

    public function test_unknown_provider_falls_back_to_null_provider(): void
    {
        config(['ai.provider' => 'openai']);

        $provider = app(AIProvider::class);

        $this->assertInstanceOf(NullAIProvider::class, $provider);
    }

    public function test_provider_can_be_swapped_via_container_binding(): void
    {
        $mock = new class implements AIProvider
        {
            public function name(): string
            {
                return 'mock';
            }

            public function summarizeIncident(AIContextDTO $context): string
            {
                return 'Mock summary';
            }

            public function suggestReply(AIContextDTO $context): string
            {
                return 'Mock reply';
            }

            public function suggestNextActions(AIContextDTO $context): array
            {
                return [new AIRecommendationDTO(title: 'Mock action')];
            }

            public function classifyIncident(AIContextDTO $context): string
            {
                return 'Mock class';
            }

            public function estimateResolution(AIContextDTO $context): string
            {
                return 'Mock ETA';
            }

            public function explainRecommendation(AIContextDTO $context, string $recommendation): string
            {
                return 'Mock explanation';
            }
        };

        $this->app->instance(AIProvider::class, $mock);

        $provider = app(AIProvider::class);

        $this->assertSame('mock', $provider->name());
    }
}
