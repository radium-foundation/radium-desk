<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_sites', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 64)->unique();
            $table->string('display_name');
            $table->json('allowed_origins')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index('is_enabled');
        });

        Schema::create('commerce_site_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_site_id')
                ->constrained('commerce_sites')
                ->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('key_hash', 64);
            $table->string('key_prefix', 8);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['commerce_site_id', 'is_active'], 'commerce_site_api_keys_site_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_site_api_keys');
        Schema::dropIfExists('commerce_sites');
    }
};
