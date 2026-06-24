<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserFirstNameTest extends TestCase
{
    public function test_first_name_returns_text_before_first_space(): void
    {
        $user = new User(['name' => 'Ravi Kumar']);

        $this->assertSame('Ravi', $user->firstName());
    }

    public function test_first_name_returns_full_name_when_no_space(): void
    {
        $user = new User(['name' => 'Ravi']);

        $this->assertSame('Ravi', $user->firstName());
    }
}
