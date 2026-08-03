<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 255);
            $table->string('category', 64);
            $table->json('channels');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('blade_view', 255)->nullable();
            $table->string('notification_type', 100)->nullable()->index();
            $table->string('runtime_source', 32)->default('blade');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'status']);
            $table->index('status');
        });

        Schema::create('communication_template_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('communication_template_id');
            $table->unsignedInteger('version');
            $table->string('subject', 998)->nullable();
            $table->string('greeting_style', 64)->nullable();
            $table->longText('body_html');
            $table->string('signature_mode', 32)->default('company_default');
            $table->json('channels')->nullable();
            $table->json('variables')->nullable();
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('communication_template_id', 'ctv_template_fk')
                ->references('id')
                ->on('communication_templates')
                ->cascadeOnDelete();
            $table->unique(['communication_template_id', 'version'], 'ctv_template_version_uq');
        });

        Schema::create('communication_template_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('communication_template_id');
            $table->unsignedBigInteger('communication_template_version_id')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 32);
            $table->string('communication_type', 64)->nullable();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->foreign('communication_template_id', 'ctu_template_fk')
                ->references('id')
                ->on('communication_templates')
                ->cascadeOnDelete();
            $table->foreign('communication_template_version_id', 'ctu_version_fk')
                ->references('id')
                ->on('communication_template_versions')
                ->nullOnDelete();
            $table->index(['communication_template_id', 'used_at'], 'ctu_template_used_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_template_usages');
        Schema::dropIfExists('communication_template_versions');
        Schema::dropIfExists('communication_templates');
    }
};
