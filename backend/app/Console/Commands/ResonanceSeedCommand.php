<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\LightDatasetSeeder;
use Database\Seeders\RealisticDatasetSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

/**
 * Commande dédiée pour seeder le dataset light ou realistic, avec option
 * `--fresh` pour reset la base avant.
 *
 * Cf. resonance-spec.md §7.
 */
class ResonanceSeedCommand extends Command
{
    protected $signature = 'resonance:seed
        {--dataset=light : Dataset à charger (light|realistic)}
        {--fresh : Drop & recrée la base avant de seeder}';

    protected $description = 'Seed le dataset Resonance (light ou realistic).';

    public function handle(): int
    {
        $dataset = $this->option('dataset');
        if (! in_array($dataset, ['light', 'realistic'], true)) {
            $this->error("--dataset doit être 'light' ou 'realistic' (reçu : {$dataset})");

            return Command::INVALID;
        }

        if ($this->option('fresh')) {
            $this->info('migrate:fresh…');
            Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        }

        // Désactive globalement les events Eloquent durant le seed.
        Model::unguard();

        try {
            $seederClass = $dataset === 'realistic'
                ? RealisticDatasetSeeder::class
                : LightDatasetSeeder::class;

            $this->info("Seeding dataset: {$dataset}");
            $this->call('db:seed', [
                '--class' => $seederClass,
                '--force' => true,
            ]);
            $this->info("Seed dataset {$dataset} terminé.");
        } finally {
            Model::reguard();
        }

        return Command::SUCCESS;
    }
}
