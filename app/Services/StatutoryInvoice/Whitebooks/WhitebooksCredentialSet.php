<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

final class WhitebooksCredentialSet
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $gstin,
        public readonly string $username,
        public readonly string $password,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $email,
        public readonly string $ipAddress,
        public readonly string $location,
    ) {}
}
