<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seeding\PlaceholderMediaService;
use Illuminate\Console\Command;

/**
 * Vérifie que le pool d'images placeholders (`seed-pool/img-*.jpg`) est
 * bien présent dans MinIO, et le restaure si besoin (cache local
 * `/seeds-dump/media/` → upload, ou téléchargement Picsum en dernier
 * recours).
 *
 * Pourquoi cette commande existe : `resonance:restore-database` ne
 * réimporte que le **schéma SQL** (events, media, tickets, etc.). Les
 * lignes `media.path` (`seed-pool/img-…jpg`) sont restaurées, mais
 * **les binaires correspondants en MinIO ne le sont pas** (le bucket
 * vit dans son propre volume Docker `minio_data` et n'est pas couvert
 * par le dump SQL).
 *
 * Conséquence si on ne reseed pas MinIO : toutes les images de la
 * stack renvoient 404, ce qui pollue silencieusement les mesures
 * Lighthouse (LCP image manquant, total byte weight sous-évalué) et
 * fait passer une régression d'images cassées pour une amélioration.
 * La cible `make restore` doit donc enchaîner les deux.
 *
 * Idempotence : `PlaceholderMediaService::ensurePool()` itère les 30
 * entrées du pool et fait un `Storage::disk('s3')->exists()` par image.
 * Si le bucket est complet, c'est ~30 HEAD requests (~ 100 ms total) —
 * négligeable. Ajouté en pre-hook automatique de `make restore` (cf.
 * Makefile) ; aucune raison de le lancer manuellement en temps normal.
 */
class ResonanceEnsureMediaPoolCommand extends Command
{
    protected $signature = 'resonance:ensure-media-pool
        {--quiet-when-complete : Sortie silencieuse si tout le pool est déjà uploadé}';

    protected $description = 'Vérifie que les images du pool placeholder sont dans MinIO ; reupload depuis le cache local au besoin.';

    public function handle(PlaceholderMediaService $service): int
    {
        $quietIfComplete = (bool) $this->option('quiet-when-complete');

        // ATTENTION : `PlaceholderMediaService::ensurePool()` invoque le
        // callback via `Closure::call($this, …)`, ce qui rebind `$this` au
        // service le temps de l'appel. On ne peut donc PAS utiliser
        // `$this->line()` / `$this->info()` à l'intérieur (ces méthodes
        // appartiennent à Command). Les `use (&$stats)` capturent en
        // revanche correctement les variables locales — c'est la voie
        // safe : on accumule des compteurs, et on imprime le résumé après.
        $stats = ['skip' => 0, 'cache' => 0, 'download' => 0];
        $start = microtime(true);

        $service->ensurePool(function (
            int $index,
            int $total,
            string $action,
            string $filename,
        ) use (&$stats): void {
            $stats[$action] = ($stats[$action] ?? 0) + 1;
        });

        $duration = microtime(true) - $start;
        $touched = $stats['cache'] + $stats['download'];

        if ($touched === 0) {
            if (! $quietIfComplete) {
                $this->info(sprintf(
                    'Pool MinIO complet : %d/%d images déjà présentes (%.2fs).',
                    $stats['skip'],
                    $stats['skip'],
                    $duration,
                ));
            }

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            'Pool MinIO restauré en %.2fs : %d skip, %d upload depuis cache, %d téléchargement Picsum.',
            $duration,
            $stats['skip'],
            $stats['cache'],
            $stats['download'],
        ));

        return Command::SUCCESS;
    }
}
