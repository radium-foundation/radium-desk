<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_mailbox_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox', 255);
            $table->string('history_id', 64)->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('baselined_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->unique('mailbox', 'gmss_mailbox_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_mailbox_sync_states');
    }
};
