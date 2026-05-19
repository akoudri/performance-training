<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Dump SQL gzippé du dataset courant vers /seeds-dump/realistic.sql.gz.
 *
 * Cf. resonance-spec.md §7. Format plain + gzip pour rester lisible/diffable
 * et permettre un restore rapide via `gunzip -c | psql`.
 */
class ResonanceDumpDatabaseCommand extends Command
{
    protected $signature = 'resonance:dump-database
        {--output=/seeds-dump/realistic.sql.gz : Chemin du fichier de sortie}';

    protected $description = 'Dump SQL gzippé de la base courante (overwrite silencieux).';

    public function handle(): int
    {
        $output = (string) $this->option('output');
        $config = config('database.connections.'.config('database.default'));

        $host = (string) ($config['host'] ?? 'postgres');
        $port = (string) ($config['port'] ?? '5432');
        $database = (string) ($config['database'] ?? 'resonance');
        $username = (string) ($config['username'] ?? 'resonance');
        $password = (string) ($config['password'] ?? '');

        @mkdir(dirname($output), 0o755, true);

        $this->info("Dump → {$output}");
        $this->line("  host={$host} db={$database} user={$username}");

        $start = microtime(true);

        $cmd = sprintf(
            'pg_dump -h %s -p %s -U %s -d %s --format=plain --no-owner --no-privileges --clean --if-exists | gzip -c > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($output),
        );

        $process = Process::fromShellCommandline($cmd, null, ['PGPASSWORD' => $password], null, 1800.0);
        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->getOutput()->write($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('pg_dump a échoué (code '.$process->getExitCode().').');

            return Command::FAILURE;
        }

        $duration = microtime(true) - $start;
        $bytes = (int) (@filesize($output) ?: 0);

        $this->info(sprintf(
            'OK — %s (%s) en %.2fs',
            $output,
            $this->humanBytes($bytes),
            $duration,
        ));

        $this->table(
            ['Fichier', 'Taille', 'Durée'],
            [[$output, $this->humanBytes($bytes), sprintf('%.2fs', $duration)]],
        );

        // Sanity check : vérifie qu'on a bien dumpé du contenu non-vide.
        if ($bytes < 1024) {
            $this->warn('Dump suspicieusement petit (< 1 Kio). Vérifie la base.');
        }

        // Affiche un compteur indicatif pour rassurer.
        $eventsCount = DB::table('events')->count();
        $ticketsCount = DB::table('tickets')->count();
        $this->line("  (référence base : events={$eventsCount}, tickets={$ticketsCount})");

        return Command::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.2f %s', $value, $units[$i]);
    }
}
