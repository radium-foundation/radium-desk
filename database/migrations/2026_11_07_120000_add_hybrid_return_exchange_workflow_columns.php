<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_exchange_cases', function (Blueprint $table): void {
            $table->string('replacement_category', 64)->nullable()->after('reason');
            $table->text('customer_request_summary')->nullable()->after('replacement_category');
            $table->string('requested_connector_change', 255)->nullable()->after('customer_request_summary');
            $table->string('planned_replacement_serial_number', 128)->nullable()->after('requested_connector_change');
            $table->unsignedBigInteger('planned_replacement_product_id')->nullable()->after('planned_replacement_serial_number');
            $table->decimal('price_difference_amount', 12, 2)->nullable()->after('planned_replacement_product_id');
            $table->boolean('price_difference_verified')->default(false)->after('price_difference_amount');
            $table->text('hybrid_workflow_notes')->nullable()->after('price_difference_verified');
        });

        Schema::create('return_exchange_case_hybrid_approvals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('return_exchange_case_id');
            $table->string('approval_type', 64);
            $table->string('decision', 32)->default('pending');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamps();

            $table->unique(['return_exchange_case_id', 'approval_type'], 'rec_hybrid_approval_case_type_unique');

            $table->foreign('return_exchange_case_id', 'rec_hybrid_appr_case_fk')
                ->references('id')
                ->on('return_exchange_cases')
                ->cascadeOnDelete();
            $table->foreign('decided_by', 'rec_hybrid_appr_decided_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_exchange_case_hybrid_approvals');

        Schema::table('return_exchange_cases', function (Blueprint $table): void {
            $table->dropColumn([
                'replacement_category',
                'customer_request_summary',
                'requested_connector_change',
                'planned_replacement_serial_number',
                'planned_replacement_product_id',
                'price_difference_amount',
                'price_difference_verified',
                'hybrid_workflow_notes',
            ]);
        });
    }
};
