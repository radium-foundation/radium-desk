<?php

namespace Tests\Feature\DriverManagement;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxDriverMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superadmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_authorize_media_returns_503_when_flag_disabled(): void
    {
        config([
            'driver_management.radiumbox_com.enabled' => true,
            'driver_management.radiumbox_com.base_url' => 'https://box.test',
            'driver_management.radiumbox_com.token' => 'desk-token',
            'driver_management.radiumbox_com.media_upload_enabled' => false,
        ]);

        $this->actingAs($this->superadmin())
            ->postJson(route('driver-management.radiumbox.authorize-media'), [
                'product_driver_id' => 1,
            ])
            ->assertStatus(503);
    }

    public function test_authorize_media_proxies_to_box_without_file_multipart(): void
    {
        config([
            'driver_management.radiumbox_com.enabled' => true,
            'driver_management.radiumbox_com.base_url' => 'https://box.test',
            'driver_management.radiumbox_com.token' => 'desk-token',
            'driver_management.radiumbox_com.media_upload_enabled' => true,
        ]);

        Http::fake([
            'https://box.test/api/integrations/v1/driver-upload/authorize' => Http::response([
                'grant_token' => 'grant.example',
                'upload_id' => '550e8400-e29b-41d4-a716-446655440000',
                'media_quarantine_url' => 'https://media.test/api/drivers/v1/quarantine',
            ], 201),
        ]);

        $user = $this->superadmin();

        $this->actingAs($user)
            ->postJson(route('driver-management.radiumbox.authorize-media'), [
                'product_driver_id' => 99,
            ])
            ->assertOk()
            ->assertJsonPath('media_quarantine_url', 'https://media.test/api/drivers/v1/quarantine');

        Http::assertSent(function (Request $request) use ($user) {
            return $request->url() === 'https://box.test/api/integrations/v1/driver-upload/authorize'
                && $request['desk_user_id'] === $user->id
                && ! $request->isMultipart();
        });
    }

    public function test_store_rejects_multipart_when_media_upload_enabled(): void
    {
        config([
            'driver_management.radiumbox_com.enabled' => true,
            'driver_management.radiumbox_com.base_url' => 'https://box.test',
            'driver_management.radiumbox_com.token' => 'desk-token',
            'driver_management.radiumbox_com.media_upload_enabled' => true,
        ]);

        Http::fake();

        $file = UploadedFile::fake()->create('driver.zip', 10, 'application/zip');

        $this->actingAs($this->superadmin())
            ->post(route('driver-management.radiumbox.store'), [
                'product_driver_id' => 1,
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('file');

        Http::assertNothingSent();
    }

    public function test_legacy_store_still_posts_quarantine_to_box_when_media_disabled(): void
    {
        config([
            'driver_management.radiumbox_com.enabled' => true,
            'driver_management.radiumbox_com.base_url' => 'https://box.test',
            'driver_management.radiumbox_com.token' => 'desk-token',
            'driver_management.radiumbox_com.media_upload_enabled' => false,
        ]);

        Http::fake([
            'https://box.test/api/integrations/v1/driver-upload/quarantine' => Http::response([
                'upload_id' => '550e8400-e29b-41d4-a716-446655440000',
                'sha256' => str_repeat('c', 64),
                'bytes' => 10,
            ], 201),
        ]);

        $file = UploadedFile::fake()->create('rb-test-driver.zip', 10, 'application/zip');

        $this->actingAs($this->superadmin())
            ->post(route('driver-management.radiumbox.store'), [
                'product_driver_id' => 1,
                'file' => $file,
            ])
            ->assertRedirect();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/driver-upload/quarantine'));
    }
}
