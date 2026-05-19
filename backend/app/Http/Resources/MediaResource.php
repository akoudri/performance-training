<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'path' => $this->path,
            // URL absolue MinIO. AWS_URL est utilisée si définie, sinon
            // path-style endpoint.
            'url' => Storage::disk('s3')->url($this->path),
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            'position' => $this->position,
            'alt_text' => $this->alt_text,
        ];
    }
}
