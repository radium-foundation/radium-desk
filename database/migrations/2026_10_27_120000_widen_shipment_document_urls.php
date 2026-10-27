<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAX_VARCHAR_URL_LENGTH = 1024;

    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            if (Schema::hasColumn('shipments', 'label_url')) {
                $table->text('label_url')->nullable()->change();
            }
            if (Schema::hasColumn('shipments', 'manifest_url')) {
                $table->text('manifest_url')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        $this->assertNoDocumentUrlsExceedVarcharLimit(self::MAX_VARCHAR_URL_LENGTH);

        Schema::table('shipments', function (Blueprint $table): void {
            if (Schema::hasColumn('shipments', 'label_url')) {
                $table->string('label_url', self::MAX_VARCHAR_URL_LENGTH)->nullable()->change();
            }
            if (Schema::hasColumn('shipments', 'manifest_url')) {
                $table->string('manifest_url', self::MAX_VARCHAR_URL_LENGTH)->nullable()->change();
            }
        });
    }

    private function assertNoDocumentUrlsExceedVarcharLimit(int $maxLength): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $lengthExpression = $driver === 'sqlite' ? 'length(%s)' : 'CHAR_LENGTH(%s)';

        foreach (['label_url', 'manifest_url'] as $column) {
            if (! Schema::hasColumn('shipments', $column)) {
                continue;
            }

            $offenders = (int) DB::table('shipments')
                ->whereNotNull($column)
                ->whereRaw(sprintf($lengthExpression, $column).' > ?', [$maxLength])
                ->count();

            if ($offenders > 0) {
                throw new \RuntimeException(
                    'Cannot roll back widen_shipment_document_urls: '
                    .$offenders.' shipment row(s) have '.$column.' longer than '.$maxLength.' characters. '
                    .'Narrowing the column would truncate presigned Shiprocket document URLs. '
                    .'Keep the TEXT columns or restore from backup before attempting rollback.'
                );
            }
        }
    }
};
