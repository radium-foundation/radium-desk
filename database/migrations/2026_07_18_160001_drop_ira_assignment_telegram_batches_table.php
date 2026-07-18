<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ira_assignment_telegram_batches');
    }

    public function down(): void
    {
        // Intentionally empty: batching is cache-based and must not be recreated.
    }
};
