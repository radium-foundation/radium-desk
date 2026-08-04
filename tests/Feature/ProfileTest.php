<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
        $response->assertSee('Dear {{customer_name}}', false);
        $response->assertSee('Hello {{customer_name}}', false);
    }

    public function test_profile_page_opens_for_admin_agent_and_superadmin(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach ([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)
                ->get('/profile')
                ->assertOk()
                ->assertSee('Profile Information', false);
        }
    }

    public function test_profile_page_opens_for_user_with_missing_optional_fields(): void
    {
        $user = User::factory()->create([
            'designation' => null,
            'department' => null,
            'phone' => null,
            'company_name' => null,
            'default_greeting_style' => null,
            'telegram_chat_id' => null,
            'telegram_notifications_enabled' => false,
            'availability_status' => null,
            'availability_updated_at' => null,
        ]);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'designation' => 'Support Lead',
                'department' => 'Operations',
                'phone' => '9999999999',
                'company_name' => 'Radium',
                'default_greeting_style' => 'dear_customer',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('dear_customer', $user->default_greeting_style);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
    }
}
