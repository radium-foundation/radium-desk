<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Domain\Cwid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CwidTest extends TestCase
{
    public function test_generates_uuid_v4(): void
    {
        $cwid = Cwid::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $cwid->value,
        );
    }

    #[DataProvider('invalidCwidProvider')]
    public function test_rejects_non_uuid_v4(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cwid::fromString($value);
    }

    public static function invalidCwidProvider(): array
    {
        return [
            'empty' => [''],
            'email' => ['user@example.com'],
            'uuid_v1' => ['6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
            'local_user_id' => ['12345'],
        ];
    }

    public function test_accepts_valid_uuid_v4(): void
    {
        $value = '550e8400-e29b-41d4-a716-446655440000';
        $cwid = Cwid::fromString($value);

        $this->assertSame($value, $cwid->value);
    }
}
