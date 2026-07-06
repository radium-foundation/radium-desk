<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('availability_status', 'on_leave')
            ->update([
                'availability_status' => 'offline',
                'availability_updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Legacy manual leave status cannot be restored reliably.
    }
};
