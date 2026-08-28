<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_catalog_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_site_id')
                ->constrained('commerce_sites')
                ->cascadeOnDelete();
            $table->string('external_slug', 64);
            $table->string('display_name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['commerce_site_id', 'external_slug'], 'commerce_catalog_brands_site_slug_unique');
            $table->index(['commerce_site_id', 'is_enabled'], 'commerce_catalog_brands_site_enabled_idx');
        });

        Schema::create('commerce_catalog_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')
                ->constrained('commerce_catalog_brands')
                ->cascadeOnDelete();
            $table->string('display_name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'is_enabled'], 'commerce_catalog_models_brand_enabled_idx');
        });

        Schema::create('commerce_catalog_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')
                ->constrained('commerce_catalog_models')
                ->cascadeOnDelete();
            $table->string('plan_type', 8);
            $table->string('display_name');
            $table->string('short_name', 64);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('publish_price', 12, 2);
            $table->decimal('regular_price', 12, 2)->default(0);
            $table->string('hsn_code', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->string('legacy_reference', 128)->nullable();
            $table->timestamps();

            $table->index(['model_id', 'plan_type', 'is_enabled'], 'commerce_catalog_plans_model_type_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_catalog_plans');
        Schema::dropIfExists('commerce_catalog_models');
        Schema::dropIfExists('commerce_catalog_brands');
    }
};
