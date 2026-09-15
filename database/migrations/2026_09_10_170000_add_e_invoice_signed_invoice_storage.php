<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('e_invoice_records')) {
            return;
        }

        Schema::table('e_invoice_records', function (Blueprint $table) {
            if (! Schema::hasColumn('e_invoice_records', 'signed_invoice_disk')) {
                $table->string('signed_invoice_disk', 32)->nullable()->after('signed_qr');
            }
            if (! Schema::hasColumn('e_invoice_records', 'signed_invoice_path')) {
                $table->string('signed_invoice_path', 255)->nullable()->after('signed_invoice_disk');
            }
            if (! Schema::hasColumn('e_invoice_records', 'signed_invoice_sha256')) {
                $table->string('signed_invoice_sha256', 64)->nullable()->after('signed_invoice_path');
            }
            if (! Schema::hasColumn('e_invoice_records', 'signed_invoice_bytes')) {
                $table->unsignedInteger('signed_invoice_bytes')->nullable()->after('signed_invoice_sha256');
            }
            if (! Schema::hasColumn('e_invoice_records', 'signed_invoice_persisted_at')) {
                $table->timestamp('signed_invoice_persisted_at')->nullable()->after('signed_invoice_bytes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('e_invoice_records')) {
            return;
        }

        Schema::table('e_invoice_records', function (Blueprint $table) {
            foreach ([
                'signed_invoice_persisted_at',
                'signed_invoice_bytes',
                'signed_invoice_sha256',
                'signed_invoice_path',
                'signed_invoice_disk',
            ] as $column) {
                if (Schema::hasColumn('e_invoice_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
