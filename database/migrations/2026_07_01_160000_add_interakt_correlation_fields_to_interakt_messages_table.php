<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interakt_messages', function (Blueprint $table) {
            $table->string('interakt_customer_id')->nullable()->after('callback_data');
            $table->string('conversation_id')->nullable()->after('interakt_customer_id');

            $table->index('interakt_customer_id');
            $table->index('conversation_id');
            $table->index('template_name');
        });
    }

    public function down(): void
    {
        Schema::table('interakt_messages', function (Blueprint $table) {
            $table->dropIndex(['interakt_customer_id']);
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['template_name']);

            $table->dropColumn([
                'interakt_customer_id',
                'conversation_id',
            ]);
        });
    }
};
