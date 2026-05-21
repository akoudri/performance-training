<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Restore rapide de la base depuis /seeds-dump/realistic.sql.gz.
 *
 * Cf. resonance-spec.md §7. Reset = DROP SCHEMA public CASCADE + recréation,
 * puis psql sur le dump décompressé. Cible : < 30s sur le dataset realistic.
 */
class ResonanceRestoreDatabaseCommand extends Command
{
    protected $signature = 'resonance:restore-database
        {--input=/seeds-dump/realistic.sql.gz : Chemin du dump à restorer}';

    protected $description = 'Drop schema public et restore le dump SQL gzippé.';

    public function handle(): int
    {
        $input = (string) $this->option('input');

        if (! is_file($input)) {
            $this->error("Fichier introuvable : {$input}");
            $this->line('Astuce : lance d\'abord `php artisan resonance:dump-database`.');

            return Command::FAILURE;
        }

        // @perf-fix: lecture explicite sur pgsql_direct (bypass PgBouncer)
        // pour exécuter DROP SCHEMA public CASCADE + import psql sans
        // entrer dans le mode transaction pooling (incompatible avec
        // les sessions longues que requiert un import multi-statements).
        $config = config('database.connections.pgsql_direct');
        $host = (string) ($config['host'] ?? 'postgres');
        $port = (string) ($config['port'] ?? '5432');
        $database = (string) ($config['database'] ?? 'resonance');
        $username = (string) ($config['username'] ?? 'resonance');
        $password = (string) ($config['password'] ?? '');

        $this->info("Restore ← {$input}");
        $this->line("  host={$host} db={$database} user={$username}");

        $start = microtime(true);

        // Reset du schéma via la connexion Eloquent directe (bypass
        // PgBouncer — les DDL CASCADE prennent un ACCESS EXCLUSIVE, qu'on
        // veut éviter de faire transiter par un pooler en transaction).
        $this->line('  • drop & recreate schema public…');
        DB::connection('pgsql_direct')->unprepared(
            'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public; '
            .'GRANT ALL ON SCHEMA public TO '.$username.'; '
            .'GRANT ALL ON SCHEMA public TO public;'
        );

        // Force la déconnexion : la session courante a perdu ses search_path.
        DB::connection('pgsql_direct')->disconnect();
        DB::disconnect();

        $this->line('  • gunzip -c | psql…');
        $cmd = sprintf(
            'gunzip -c %s | psql -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1 --quiet --no-psqlrc',
            escapeshellarg($input),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
        );

        $process = Process::fromShellCommandline($cmd, null, ['PGPASSWORD' => $password], null, 1800.0);
        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->getOutput()->write($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('psql a échoué (code '.$process->getExitCode().').');

            return Command::FAILURE;
        }

        $duration = microtime(true) - $start;

        $eventsCount = DB::table('events')->count();
        $ticketsCount = DB::table('tickets')->count();

        $this->info(sprintf('OK — restore terminé en %.2fs', $duration));
        $this->line("  (vérification base : events={$eventsCount}, tickets={$ticketsCount})");

        if ($duration > 30.0) {
            $this->warn(sprintf(
                'Durée %.2fs dépasse la cible §7 (< 30s). Vérifie le tuning Postgres ou la taille du dump.',
                $duration,
            ));
        }

        return Command::SUCCESS;
    }
}
