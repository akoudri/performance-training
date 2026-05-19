<?php

declare(strict_types=1);

namespace App\Services\Seeding;

/** Une entrée du pool d'images placeholders. */
final class PoolEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
