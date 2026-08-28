<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\CommerceSite;
use App\Models\Commerce\CommerceSiteApiKey;
use App\Services\Commerce\CommerceSiteApiKeyHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceApiSkeletonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'commerce.enabled' => true,
        ]);
    }

    public function test_health_endpoint_returns_capability_metadata_without_authentication(): void
    {
        config(['commerce.enabled' => false]);

        $response = $this->getJson('/api/v1/commerce/health');

        $response->assertOk()
            ->assertJson([
                'service' => 'radium-desk-commerce',
                'api_version' => '1',
                'commerce_enabled' => false,
                'application' => 'Radium Desk',
            ]);
    }

    public function test_protected_site_endpoint_returns_service_unavailable_when_commerce_disabled(): void
    {
        config(['commerce.enabled' => false]);

        [$site, $plainKey] = $this->createSiteWithApiKey();

        $response = $this->commerceGet('/api/v1/commerce/site', $site->site_id, $plainKey);

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'Commerce is disabled.',
            ]);
    }

    public function test_valid_site_authentication_allows_protected_endpoint(): void
    {
        [$site, $plainKey] = $this->createSiteWithApiKey([
            'site_id' => 'rdserviceonline',
            'display_name' => 'RD Service Online',
        ]);

        $response = $this->commerceGet('/api/v1/commerce/site', $site->site_id, $plainKey);

        $response->assertOk()
            ->assertJson([
                'site_id' => 'rdserviceonline',
                'display_name' => 'RD Service Online',
            ]);
    }

    public function test_missing_site_id_header_is_rejected(): void
    {
        [, $plainKey] = $this->createSiteWithApiKey();

        $response = $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->getJson('/api/v1/commerce/site');

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_missing_authorization_header_is_rejected(): void
    {
        [$site] = $this->createSiteWithApiKey();

        $response = $this->withHeader('X-Site-Id', $site->site_id)
            ->getJson('/api/v1/commerce/site');

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        [$site] = $this->createSiteWithApiKey();

        $response = $this->commerceGet('/api/v1/commerce/site', $site->site_id, 'invalid-api-key-value');

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_disabled_site_is_rejected(): void
    {
        [$site, $plainKey] = $this->createSiteWithApiKey([
            'is_enabled' => false,
        ]);

        $response = $this->commerceGet('/api/v1/commerce/site', $site->site_id, $plainKey);

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_unknown_site_id_is_rejected_without_leaking_registry_state(): void
    {
        [, $plainKey] = $this->createSiteWithApiKey();

        $response = $this->commerceGet('/api/v1/commerce/site', 'unknown-site', $plainKey);

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    /**
     * @param  array<string, mixed>  $siteAttributes
     * @return array{0: CommerceSite, 1: string}
     */
    private function createSiteWithApiKey(array $siteAttributes = []): array
    {
        $hasher = app(CommerceSiteApiKeyHasher::class);
        $plainKey = 'rdsk_test_'.Str::random(40);

        $site = CommerceSite::query()->create([
            'site_id' => $siteAttributes['site_id'] ?? 'test-site-'.Str::lower(Str::random(8)),
            'display_name' => $siteAttributes['display_name'] ?? 'Test Commerce Site',
            'allowed_origins' => $siteAttributes['allowed_origins'] ?? ['https://rdserviceonline.in'],
            'is_enabled' => $siteAttributes['is_enabled'] ?? true,
        ]);

        CommerceSiteApiKey::query()->create([
            'commerce_site_id' => $site->id,
            'name' => 'test',
            'key_hash' => $hasher->hash($plainKey),
            'key_prefix' => substr($plainKey, 0, 8),
            'is_active' => true,
        ]);

        return [$site, $plainKey];
    }

    private function commerceGet(string $uri, string $siteId, string $plainKey)
    {
        return $this->withHeaders([
            'X-Site-Id' => $siteId,
            'Authorization' => 'Bearer '.$plainKey,
        ])->getJson($uri);
    }
}
