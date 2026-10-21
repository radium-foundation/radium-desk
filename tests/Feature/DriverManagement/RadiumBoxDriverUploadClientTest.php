<?php

namespace Tests\Feature\DriverManagement;

use App\Models\User;
use App\Services\RadiumBox\RadiumBoxDriverUploadClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxDriverUploadClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_does_not_expose_token_in_view_config(): void
    {
        config([
            'driver_management.radiumbox_com.enabled' => true,
            'driver_management.radiumbox_com.base_url' => 'https://box.test',
            'driver_management.radiumbox_com.token' => 'secret-integration-token',
        ]);

        Http::fake([
            'https://box.test/api/integrations/v1/driver-upload/catalog' => Http::response([
                'site_code' => 'radiumbox_com',
                'drivers' => [],
            ], 200),
        ]);

        $user = User::factory()->create();
        $client = app(RadiumBoxDriverUploadClient::class);
        $client->fetchCatalog();

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer secret-integration-token');
        });

        $this->assertTrue($client->isConfigured());
    }

    public function test_malformed_json_response_surfaces_validation_error(): void
    {
        config([
            'driver_management.radiumbox_com.enabled' => true,
            'driver_management.radiumbox_com.base_url' => 'https://box.test',
            'driver_management.radiumbox_com.token' => 'secret-integration-token',
        ]);

        Http::fake([
            'https://box.test/api/integrations/v1/driver-upload/catalog' => Http::response('not-json', 500),
        ]);

        $client = app(RadiumBoxDriverUploadClient::class);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        $client->fetchCatalog();
    }
}
