<?php

namespace Database\Seeders;

use App\Services\Commerce\CommerceCatalogImporter;
use Illuminate\Database\Seeder;

class CommerceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $fixture = database_path('fixtures/commerce/catalog-rdserviceonline.json');

        app(CommerceCatalogImporter::class)->importFromFixture($fixture);
    }
}
