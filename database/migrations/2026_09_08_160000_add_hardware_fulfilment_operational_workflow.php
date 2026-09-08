<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('label_url', 1024)->nullable()->after('pickup_requested_at');
            $table->timestamp('label_fetched_at')->nullable()->after('label_url');
            $table->string('manifest_id', 64)->nullable()->after('label_fetched_at');
            $table->string('manifest_url', 1024)->nullable()->after('manifest_id');
            $table->timestamp('manifest_generated_at')->nullable()->after('manifest_url');
        });

        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->timestamp('ready_for_pickup_at')->nullable()->after('awb_assigned_at');
        });

        Schema::create('hardware_fulfilment_package_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hardware_fulfilment_id')->constrained('hardware_fulfilments')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('disk', 32)->default('local');
            $table->string('path', 255);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->unique(['hardware_fulfilment_id', 'kind'], 'hw_pkg_ev_fulfilment_kind_unique');
            $table->index('kind', 'hw_pkg_ev_kind_idx');
            $table->foreign('uploaded_by_user_id', 'hw_pkg_ev_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_fulfilment_package_evidences');

        Schema::table('hardware_fulfilments', function (Blueprint $table) {
            $table->dropColumn('ready_for_pickup_at');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'label_url',
                'label_fetched_at',
                'manifest_id',
                'manifest_url',
                'manifest_generated_at',
            ]);
        });
    }
};
