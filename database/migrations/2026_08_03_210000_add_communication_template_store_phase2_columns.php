<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_templates', function (Blueprint $table) {
            $table->unsignedInteger('approved_version')->nullable()->after('current_version');
            $table->unsignedInteger('fallback_count')->default(0)->after('usage_count');
            $table->timestamp('last_fallback_at')->nullable()->after('last_used_at');
            $table->timestamp('last_send_at')->nullable()->after('last_fallback_at');
            $table->string('last_runtime_source', 32)->nullable()->after('runtime_source');
            $table->text('last_error')->nullable()->after('last_runtime_source');
            $table->boolean('is_reply_playbook')->default(false)->after('notification_type');
            $table->string('playbook_scope', 32)->nullable()->after('is_reply_playbook');
            $table->foreignId('owner_user_id')->nullable()->after('playbook_scope')->constrained('users')->nullOnDelete();
        });

        Schema::table('communication_template_usages', function (Blueprint $table) {
            $table->unsignedTinyInteger('edit_percent')->nullable()->after('communication_type');
            $table->unsignedInteger('send_duration_ms')->nullable()->after('edit_percent');
            $table->string('runtime_source', 32)->nullable()->after('send_duration_ms');
            $table->boolean('used_fallback')->default(false)->after('runtime_source');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('designation', 120)->nullable()->after('email');
            $table->string('department', 120)->nullable()->after('designation');
            $table->string('phone', 40)->nullable()->after('department');
            $table->string('company_name', 160)->nullable()->after('phone');
            $table->string('default_greeting_style', 64)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('communication_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropColumn([
                'approved_version',
                'fallback_count',
                'last_fallback_at',
                'last_send_at',
                'last_runtime_source',
                'last_error',
                'is_reply_playbook',
                'playbook_scope',
            ]);
        });

        Schema::table('communication_template_usages', function (Blueprint $table) {
            $table->dropColumn(['edit_percent', 'send_duration_ms', 'runtime_source', 'used_fallback']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'designation',
                'department',
                'phone',
                'company_name',
                'default_greeting_style',
            ]);
        });
    }
};
