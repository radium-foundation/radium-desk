<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_email_learning_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_type', 32);
            $table->string('match_value', 255);
            $table->string('decision_type', 32);
            $table->string('decision_value', 255);
            $table->unsignedTinyInteger('confidence')->default(80);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'rule_type', 'match_value'], 'iem_learning_rules_match_idx');
            $table->index(['decision_type', 'enabled'], 'iem_learning_rules_decision_idx');
            $table->unique(
                ['rule_type', 'match_value', 'decision_type'],
                'iem_learning_rules_unique_match',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_email_learning_rules');
    }
};
