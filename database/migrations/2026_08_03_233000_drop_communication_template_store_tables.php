<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P[03-08]-002 cleanup: drop Communication Template Store tables and
 * remove related Spatie permission rows. Idempotent and scoped.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PERMISSION_NAMES = [
        'communication-templates.view',
        'communication-templates.manage',
    ];

    public function up(): void
    {
        // Child tables first (FK-safe even if cascade is absent).
        Schema::dropIfExists('communication_template_usages');
        Schema::dropIfExists('communication_template_versions');
        Schema::dropIfExists('communication_templates');

        $this->removeStorePermissions();
    }

    public function down(): void
    {
        // Irreversible data drop — recreate via original store migrations if needed.
    }

    private function removeStorePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::PERMISSION_NAMES)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->delete();
    }
};
