<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        $maxSequence = DB::table('incidents')
            ->where(function ($query): void {
                $query->where('reference_no', 'like', 'SC-%')
                    ->orWhere('reference_no', 'like', 'SC%');
            })
            ->pluck('reference_no')
            ->map(fn (string $reference): int => $this->extractSequence($reference))
            ->max() ?? 0;

        DB::table('reference_sequences')->insert([
            'name' => 'sc',
            'current_value' => $maxSequence,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }

    private function extractSequence(string $reference): int
    {
        if (preg_match('/^SC-?(\d+)$/i', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
};
