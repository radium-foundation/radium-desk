<?php

namespace App\Services\Commerce;

class CommerceSiteApiKeyHasher
{
    public function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public function verify(string $plainKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($plainKey));
    }
}
