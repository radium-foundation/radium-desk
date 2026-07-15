<?php

namespace App\Services;

use App\Data\SupportContact;

class SupportContactResolver
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function mergeIntoVariables(array $variables): array
    {
        return array_merge($variables, $this->resolve($variables)->toViewVariables());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function resolve(array $overrides = []): SupportContact
    {
        $email = $this->trimString($overrides['support_email'] ?? '');
        $phone = $this->trimString($overrides['support_phone'] ?? '');
        $whatsapp = $this->trimString($overrides['support_whatsapp'] ?? '');
        $website = $this->trimString($overrides['support_website'] ?? '');

        if ($email === '') {
            $email = $this->trimString(config('support_contact.email', ''));
        }

        if ($phone === '') {
            $phone = $this->trimString(config('support_contact.phone', ''));
        }

        if ($email === '' && $phone === '' && array_key_exists('support_contact', $overrides)) {
            [$parsedEmail, $parsedPhone] = $this->parseLegacySupportContact(
                $this->trimString($overrides['support_contact']),
            );
            $email = $parsedEmail;
            $phone = $parsedPhone;
        }

        if ($email === '' && $phone === '') {
            [$parsedEmail, $parsedPhone] = $this->parseLegacySupportContact(
                $this->trimString(config('support_contact.contact', '')),
            );
            $email = $parsedEmail;
            $phone = $parsedPhone;
        }

        if ($whatsapp === '') {
            $whatsapp = $this->trimString(config('support_contact.whatsapp', ''));
        }

        if ($website === '') {
            $website = $this->trimString(config('support_contact.website', ''));
        }

        return new SupportContact(
            email: $email,
            phone: $phone,
            whatsapp: $whatsapp,
            website: $website,
        );
    }

    public function phoneTelHref(string $phone): string
    {
        $normalized = preg_replace('/[^\d+]/', '', $phone) ?? '';

        return $normalized !== '' ? 'tel:'.$normalized : '';
    }

    public function whatsappHref(string $whatsapp): string
    {
        if ($whatsapp === '') {
            return '';
        }

        if (filter_var($whatsapp, FILTER_VALIDATE_URL)) {
            return $whatsapp;
        }

        $digits = preg_replace('/\D+/', '', $whatsapp) ?? '';

        return $digits !== '' ? 'https://wa.me/'.$digits : '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseLegacySupportContact(string $contact): array
    {
        $email = '';
        $phone = '';

        if (preg_match('/Email:\s*(.+?)(?:\R|$)/i', $contact, $matches) === 1) {
            $email = trim($matches[1]);
        } elseif (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $email = $contact;
        }

        if (preg_match('/Phone:\s*(.+?)(?:\R|$)/i', $contact, $matches) === 1) {
            $phone = trim($matches[1]);
        }

        return [$email, $phone];
    }

    private function trimString(mixed $value): string
    {
        return trim((string) $value);
    }
}
