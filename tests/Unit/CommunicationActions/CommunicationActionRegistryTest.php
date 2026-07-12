<?php

namespace Tests\Unit\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\NotificationChannelType;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use Tests\TestCase;

class CommunicationActionRegistryTest extends TestCase
{
    public function test_registry_loads_all_phase_one_actions(): void
    {
        $registry = app(CommunicationActionRegistry::class);

        $this->assertCount(5, $registry->all());
        $this->assertTrue($registry->has(CommunicationActionKey::DriverInstallationGuide));
        $this->assertTrue($registry->has(CommunicationActionKey::ReviewRequest));
        $this->assertTrue($registry->has(CommunicationActionKey::RefundConfirmation));
        $this->assertTrue($registry->has(CommunicationActionKey::BuyRdService));
        $this->assertTrue($registry->has(CommunicationActionKey::BuyProduct));
    }

    public function test_driver_installation_guide_definition_has_expected_channels(): void
    {
        $definition = app(CommunicationActionRegistry::class)
            ->get(CommunicationActionKey::DriverInstallationGuide);

        $this->assertSame('Driver Installation Guide', $definition->name);
        $this->assertTrue($definition->supportsChannel(NotificationChannelType::WhatsApp));
        $this->assertTrue($definition->supportsChannel(NotificationChannelType::Email));
    }

    public function test_refund_confirmation_is_admin_only(): void
    {
        $definition = app(CommunicationActionRegistry::class)
            ->get(CommunicationActionKey::RefundConfirmation);

        $this->assertSame(
            ['admin', 'operations_admin', 'superadmin'],
            $definition->allowedRoles,
        );
    }
}
