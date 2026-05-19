<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Orchestrateur. Par défaut, lance le LightDatasetSeeder (rapide, dev).
 * Pour le dataset réaliste : `php artisan resonance:seed --dataset=realistic`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(LightDatasetSeeder::class);
    }
}
