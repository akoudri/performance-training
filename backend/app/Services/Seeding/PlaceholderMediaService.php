<?php

declare(strict_types=1);

namespace App\Services\Seeding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Gère le pool de ~30 images placeholders utilisées par les seeders.
 *
 * Politique :
 *   1. Vérifier la présence de chaque image dans MinIO sous `seed-pool/`.
 *   2. Sinon, vérifier le cache local `/seeds-dump/media/` (bind-mount host).
 *   3. Sinon, télécharger depuis picsum.photos (graine déterministe).
 *   4. Uploader dans MinIO et conserver le binaire en cache local.
 *
 * Échec propre si offline + cache vide (RuntimeException explicite).
 */
class PlaceholderMediaService
{
    private const CACHE_DIR = '/seeds-dump/media';

    private const MINIO_PREFIX = 'seed-pool';

    /**
     * Spec du pool : 15× 16:9, 10× 4:3, 5× 1:1 — total 30.
     * Tailles ≥ 1500px côté long pour rester pertinent en perf
     * (cf. resonance-spec.md §7 et §8).
     *
     * @var list<array{ratio: string, width: int, height: int}>
     */
    private const POOL_SPECS = [
        // 16:9 → 1920×1080
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        ['ratio' => '16-9', 'width' => 1920, 'height' => 1080],
        // 4:3 → 1600×1200
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        ['ratio' => '4-3', 'width' => 1600, 'height' => 1200],
        // 1:1 → 1500×1500
        ['ratio' => '1-1', 'width' => 1500, 'height' => 1500],
        ['ratio' => '1-1', 'width' => 1500, 'height' => 1500],
        ['ratio' => '1-1', 'width' => 1500, 'height' => 1500],
        ['ratio' => '1-1', 'width' => 1500, 'height' => 1500],
        ['ratio' => '1-1', 'width' => 1500, 'height' => 1500],
    ];

    /** @var list<PoolEntry>|null */
    private static ?array $loadedPool = null;

    /**
     * Garantit la disponibilité du pool (MinIO + cache local) et le retourne.
     *
     * @return list<PoolEntry>
     */
    public function ensurePool(?callable $progress = null): array
    {
        if (self::$loadedPool !== null) {
            return self::$loadedPool;
        }

        if (! is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0775, true);
        }

        $disk = Storage::disk('s3');
        $pool = [];

        foreach (self::POOL_SPECS as $index => $spec) {
            $idx = $index + 1;
            $filename = sprintf('img-%s-%02d.jpg', $spec['ratio'], $idx);
            $minioPath = self::MINIO_PREFIX.'/'.$filename;
            $cachePath = self::CACHE_DIR.'/'.$filename;

            $entry = new PoolEntry(
                path: $minioPath,
                mimeType: 'image/jpeg',
                width: $spec['width'],
                height: $spec['height'],
            );

            if ($disk->exists($minioPath)) {
                $pool[] = $entry;
                $progress?->call($this, $idx, count(self::POOL_SPECS), 'skip', $filename);

                continue;
            }

            if (! is_file($cachePath)) {
                // Graine déterministe pour stabilité des seeds.
                $url = sprintf(
                    'https://picsum.photos/seed/resonance-%d/%d/%d.jpg',
                    $idx,
                    $spec['width'],
                    $spec['height'],
                );

                try {
                    $bytes = Http::timeout(10)->retry(2, 500)->get($url)->throw()->body();
                } catch (ConnectionException $e) {
                    throw new RuntimeException(
                        "Téléchargement de '{$filename}' impossible (offline ?) ".
                        "et cache local vide à '{$cachePath}'. Connecte-toi ".
                        'au réseau pour amorcer le pool, puis relance le seeder. '.
                        '(détail : '.$e->getMessage().')',
                        previous: $e,
                    );
                }

                file_put_contents($cachePath, $bytes);
                $progress?->call($this, $idx, count(self::POOL_SPECS), 'download', $filename);
            } else {
                $progress?->call($this, $idx, count(self::POOL_SPECS), 'cache', $filename);
            }

            $disk->put($minioPath, file_get_contents($cachePath), [
                'ContentType' => 'image/jpeg',
            ]);

            $pool[] = $entry;
        }

        return self::$loadedPool = $pool;
    }

    /**
     * Choisit une entrée au hasard (utilisable une fois ensurePool() appelé).
     */
    public function randomPoolEntry(): PoolEntry
    {
        if (self::$loadedPool === null) {
            throw new RuntimeException('Pool non chargé : appelez ensurePool() avant randomPoolEntry().');
        }

        return self::$loadedPool[array_rand(self::$loadedPool)];
    }

    /** Réinitialise l'état statique (utile en tests). */
    public static function reset(): void
    {
        self::$loadedPool = null;
    }
}
