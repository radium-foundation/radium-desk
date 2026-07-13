<?php

use App\Models\DeviceModel;
use App\Services\DeviceModelAliasNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_model_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_model_id')->constrained('device_models')->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->timestamps();

            $table->unique('normalized_alias');
        });

        if (! Schema::hasTable('device_models')) {
            return;
        }

        $normalizer = app(DeviceModelAliasNormalizer::class);
        $now = now();

        DeviceModel::query()
            ->get(['id', 'name', 'code'])
            ->each(function (DeviceModel $deviceModel) use ($normalizer, $now): void {
                $aliases = array_filter([
                    $deviceModel->name,
                    $deviceModel->code,
                ], fn (?string $value): bool => filled($value));

                foreach ($aliases as $alias) {
                    $normalizedAlias = $normalizer->normalize((string) $alias);

                    if ($normalizedAlias === '') {
                        continue;
                    }

                    DB::table('device_model_aliases')->insertOrIgnore([
                        'device_model_id' => $deviceModel->id,
                        'alias' => (string) $alias,
                        'normalized_alias' => $normalizedAlias,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_model_aliases');
    }
};
