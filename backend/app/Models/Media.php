<?php

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'mediable_type',
    'mediable_id',
    'type',
    'path',
    'mime_type',
    'width',
    'height',
    'duration_seconds',
    'position',
    'alt_text',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /** Le pluriel naturel de "media" est lui-même : `medias` casse SEO/lecture. */
    protected $table = 'media';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
