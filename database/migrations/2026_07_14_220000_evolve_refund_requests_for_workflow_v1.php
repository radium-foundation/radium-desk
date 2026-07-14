<?php

use App\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE refund_requests MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'");
        }

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->string('customer_preferred_method', 32)->nullable()->after('reason');
            $table->string('approved_refund_method', 32)->nullable()->after('customer_preferred_method');

            $table->decimal('total_paid_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('already_refunded_amount', 10, 2)->nullable()->after('total_paid_amount');
            $table->decimal('maximum_refundable', 10, 2)->nullable()->after('already_refunded_amount');
            $table->decimal('cancellation_charges', 10, 2)->nullable()->after('maximum_refundable');
            $table->decimal('gst_on_cancellation', 10, 2)->nullable()->after('cancellation_charges');
            $table->decimal('other_deduction', 10, 2)->nullable()->after('gst_on_cancellation');
            $table->decimal('total_deduction', 10, 2)->nullable()->after('other_deduction');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('total_deduction');

            $table->string('deduction_profile_key', 64)->nullable()->after('refund_amount');
            $table->string('partial_difference_reason', 64)->nullable()->after('deduction_profile_key');
            $table->text('partial_difference_notes')->nullable()->after('partial_difference_reason');

            $table->text('reject_reason')->nullable()->after('review_notes');

            $table->string('execution_reference_no', 100)->nullable()->after('refund_transaction_id');
            $table->string('execution_transaction_id', 100)->nullable()->after('execution_reference_no');
            $table->text('execution_remarks')->nullable()->after('execution_transaction_id');
            $table->foreignId('executed_by')->nullable()->after('execution_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable()->after('executed_by');
            $table->timestamp('closed_at')->nullable()->after('executed_at');

            $table->json('communication_channels')->nullable()->after('closed_at');
            $table->json('deduction_snapshot')->nullable()->after('communication_channels');

            $table->index('approved_refund_method');
            $table->index('executed_by');
        });

        DB::table('refund_requests')
            ->where('status', RefundStatus::Approved->value)
            ->orderBy('id')
            ->each(function (object $row): void {
                $hasTxn = filled($row->refund_transaction_id ?? null);

                DB::table('refund_requests')->where('id', $row->id)->update([
                    'status' => $hasTxn
                        ? RefundStatus::Completed->value
                        : RefundStatus::PendingExecution->value,
                    'execution_transaction_id' => $hasTxn ? $row->refund_transaction_id : null,
                    'refund_amount' => $row->amount,
                    'executed_at' => $hasTxn ? $row->reviewed_at : null,
                    'executed_by' => $hasTxn ? $row->reviewed_by : null,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('refund_requests')
            ->whereIn('status', [
                RefundStatus::PendingExecution->value,
                RefundStatus::Completed->value,
                RefundStatus::Closed->value,
            ])
            ->update(['status' => RefundStatus::Approved->value]);

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropIndex(['approved_refund_method']);
            $table->dropConstrainedForeignId('executed_by');

            $table->dropColumn([
                'customer_preferred_method',
                'approved_refund_method',
                'total_paid_amount',
                'already_refunded_amount',
                'maximum_refundable',
                'cancellation_charges',
                'gst_on_cancellation',
                'other_deduction',
                'total_deduction',
                'refund_amount',
                'deduction_profile_key',
                'partial_difference_reason',
                'partial_difference_notes',
                'reject_reason',
                'execution_reference_no',
                'execution_transaction_id',
                'execution_remarks',
                'executed_at',
                'closed_at',
                'communication_channels',
                'deduction_snapshot',
            ]);
        });
    }
};
