<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reference_sequences')) {
            return;
        }

        DB::table('reference_sequences')->updateOrInsert(
            ['name' => 'pos_return_refund_operational'],
            [
                'current_value' => 999,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('reference_sequences')) {
            return;
        }

        DB::table('reference_sequences')->where('name', 'pos_return_refund_operational')->delete();
    }
};
