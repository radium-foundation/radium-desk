<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->default('')->after('id');
            $table->string('last_name')->default('')->after('first_name');
            $table->softDeletes();
        });

        DB::table('users')->orderBy('id')->lazy()->each(function (object $user): void {
            $name = trim((string) $user->name);
            $firstName = $name !== '' ? strtok($name, ' ') : '';
            $lastName = $name !== '' ? trim(substr($name, strlen($firstName))) : '';

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $firstName ?: 'User',
                'last_name' => ltrim($lastName),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
