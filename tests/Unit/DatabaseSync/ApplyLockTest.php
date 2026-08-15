<?php

namespace Tests\Unit\DatabaseSync;

use App\Infrastructure\DatabaseSync\ApplyLock;
use RuntimeException;
use Tests\TestCase;

class ApplyLockTest extends TestCase
{
    public function test_concurrent_apply_lock_is_rejected(): void
    {
        $path = storage_path('framework/testing/apply-lock-'.uniqid('', true));
        config(['database-sync.apply_lock_path' => $path]);

        try {
            $lock = new ApplyLock;
            $lock->acquire('generation-a');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already held');

            $lock->acquire('generation-b');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
