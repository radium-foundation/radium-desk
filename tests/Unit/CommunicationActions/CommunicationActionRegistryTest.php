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

    public function test_production_actions_opt_into_closed_incident_availability(): void
    {
        $registry = app(CommunicationActionRegistry::class);

        foreach ([
            CommunicationActionKey::DriverInstallationGuide,
            CommunicationActionKey::ReviewRequest,
            CommunicationActionKey::RefundConfirmation,
            CommunicationActionKey::BuyRdService,
            CommunicationActionKey::BuyProduct,
        ] as $actionKey) {
            $this->assertTrue(
                $registry->get($actionKey)->allowedOnClosedIncident,
                "Expected [{$actionKey->value}] to allow closed incidents.",
            );
        }
    }

    public function test_definition_defaults_closed_incident_availability_to_false(): void
    {
        $definition = \App\Data\CommunicationActions\CommunicationActionDefinition::fromConfig([
            'key' => 'review_request',
            'name' => 'Future Action',
            'notification_type' => 'review_request',
            'channels' => ['whatsapp'],
        ]);

        $this->assertFalse($definition->allowedOnClosedIncident);
    }
}
