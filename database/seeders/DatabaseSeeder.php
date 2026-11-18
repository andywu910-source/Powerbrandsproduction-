<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Starter settings, process templates, checklists and staff names, then the product
     * list from the Production Log. Both are safe to re-run. The first admin is still
     * created with: php artisan pbp:create-admin
     */
    public function run(): void
    {
        $this->call(StarterDataSeeder::class);
        Artisan::call('pbp:import-products');
    }
}
