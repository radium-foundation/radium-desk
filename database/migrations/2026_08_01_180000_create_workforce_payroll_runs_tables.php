<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_payroll_month_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('month');
            $table->string('status', 16)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('calculation_version', 64);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('month');
            $table->index(['status', 'month']);
        });

        Schema::create('workforce_payroll_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('workforce_payroll_month_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('salary_revision_id')->nullable()->constrained('workforce_employee_salaries')->nullOnDelete();
            $table->decimal('monthly_salary_snapshot', 12, 2);
            $table->unsignedSmallInteger('calendar_days');
            $table->decimal('day_rate', 12, 4);
            $table->decimal('payable_days', 8, 1);
            $table->decimal('non_payable_days', 8, 1);
            $table->decimal('gross_salary', 12, 2);
            $table->decimal('net_salary', 12, 2);
            $table->json('attendance_summary_json');
            $table->timestamps();

            $table->unique(['run_id', 'user_id']);
            $table->index(['user_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_run_lines');
        Schema::dropIfExists('workforce_payroll_month_runs');
    }
};
