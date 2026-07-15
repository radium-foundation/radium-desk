<?php

namespace App\Data;

readonly class SupportContact
{
    public function __construct(
        public string $email = '',
        public string $phone = '',
        public string $whatsapp = '',
        public string $website = '',
    ) {}

    public function hasAny(): bool
    {
        return $this->email !== ''
            || $this->phone !== ''
            || $this->whatsapp !== ''
            || $this->website !== '';
    }

    /**
     * @return array{
     *     support_email: string,
     *     support_phone: string,
     *     support_whatsapp: string,
     *     support_website: string,
     * }
     */
    public function toViewVariables(): array
    {
        return [
            'support_email' => $this->email,
            'support_phone' => $this->phone,
            'support_whatsapp' => $this->whatsapp,
            'support_website' => $this->website,
        ];
    }
}
