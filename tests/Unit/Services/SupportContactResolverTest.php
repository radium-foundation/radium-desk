<?php

namespace Tests\Unit\Services;

use App\Services\SupportContactConfiguration;
use App\Services\SupportContactResolver;
use App\Services\SettingService;
use Tests\TestCase;

class SupportContactResolverTest extends TestCase
{
    public function test_resolve_prefers_structured_fields_over_legacy_support_contact(): void
    {
        config([
            'support_contact.email' => 'config@example.com',
            'support_contact.phone' => '+91 1111111111',
        ]);

        $contact = app(SupportContactResolver::class)->resolve([
            'support_email' => 'override@example.com',
            'support_phone' => '+91 9999999999',
            'support_contact' => 'legacy@example.com',
        ]);

        $this->assertSame('override@example.com', $contact->email);
        $this->assertSame('+91 9999999999', $contact->phone);
    }

    public function test_resolve_parses_legacy_multiline_support_contact(): void
    {
        config([
            'support_contact.email' => '',
            'support_contact.phone' => '',
            'support_contact.contact' => '',
        ]);

        $contact = app(SupportContactResolver::class)->resolve([
            'support_contact' => "Email: legacy@example.com\nPhone: +91 8888888888",
        ]);

        $this->assertSame('legacy@example.com', $contact->email);
        $this->assertSame('+91 8888888888', $contact->phone);
    }

    public function test_resolve_treats_legacy_email_only_support_contact_as_email(): void
    {
        config([
            'support_contact.email' => '',
            'support_contact.phone' => '',
            'support_contact.contact' => '',
        ]);

        $contact = app(SupportContactResolver::class)->resolve([
            'support_contact' => 'support@radiumbox.com',
        ]);

        $this->assertSame('support@radiumbox.com', $contact->email);
        $this->assertSame('', $contact->phone);
    }

    public function test_resolve_falls_back_to_config_defaults(): void
    {
        config([
            'support_contact.email' => 'config@example.com',
            'support_contact.phone' => '+91 7777777777',
            'support_contact.whatsapp' => '+91 6666666666',
            'support_contact.website' => 'https://radiumbox.com',
            'support_contact.contact' => '',
        ]);

        $contact = app(SupportContactResolver::class)->resolve([]);

        $this->assertSame('config@example.com', $contact->email);
        $this->assertSame('+91 7777777777', $contact->phone);
        $this->assertSame('+91 6666666666', $contact->whatsapp);
        $this->assertSame('https://radiumbox.com', $contact->website);
    }

    public function test_merge_into_variables_exposes_structured_view_keys(): void
    {
        config([
            'support_contact.email' => 'support@radiumbox.com',
            'support_contact.phone' => '+91 XXXXX XXXXX',
        ]);

        $variables = app(SupportContactResolver::class)->mergeIntoVariables([
            'customer_name' => 'Jane Doe',
            'support_contact' => 'support@radiumbox.com',
        ]);

        $this->assertSame('Jane Doe', $variables['customer_name']);
        $this->assertSame('support@radiumbox.com', $variables['support_email']);
        $this->assertSame('+91 XXXXX XXXXX', $variables['support_phone']);
    }

    public function test_phone_tel_href_normalizes_display_formatting(): void
    {
        $resolver = app(SupportContactResolver::class);

        $this->assertSame('tel:+919999999999', $resolver->phoneTelHref('+91 99999 99999'));
    }

    public function test_whatsapp_href_builds_wa_me_link_from_phone_number(): void
    {
        $resolver = app(SupportContactResolver::class);

        $this->assertSame('https://wa.me/919999999999', $resolver->whatsappHref('+91 99999 99999'));
    }

    public function test_whatsapp_href_preserves_full_url(): void
    {
        $resolver = app(SupportContactResolver::class);

        $this->assertSame(
            'https://wa.me/message/EXAMPLE',
            $resolver->whatsappHref('https://wa.me/message/EXAMPLE'),
        );
    }
}
