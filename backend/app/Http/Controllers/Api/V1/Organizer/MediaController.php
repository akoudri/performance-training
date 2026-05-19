<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Event;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Upload / delete média pour un événement.
 *
 * @perf-debt: upload synchrone vers MinIO sans génération de variantes
 *             (thumbnail, webp, avif). Résolu en J3 via job en queue.
 */
class MediaController extends Controller
{
    public function store(StoreMediaRequest $request, Event $event): JsonResponse
    {
        $this->authorizeOwnership($request, $event);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $filename = sprintf(
            'events/%d/%s.%s',
            $event->id,
            (string) Str::uuid(),
            $extension,
        );

        Storage::disk('s3')->put($filename, file_get_contents($file->getRealPath()), [
            'ContentType' => $file->getMimeType(),
        ]);

        $type = str_starts_with($file->getMimeType(), 'video/')
            ? Media::TYPE_VIDEO
            : Media::TYPE_IMAGE;

        $media = Media::create([
            'mediable_type' => Event::class,
            'mediable_id' => $event->id,
            'type' => $type,
            'path' => $filename,
            'mime_type' => $file->getMimeType(),
            'width' => null, // @perf-debt: pas d'extraction dimensions — résolu en J3.
            'height' => null,
            'duration_seconds' => null,
            'position' => (int) $request->integer('position', 0),
            'alt_text' => $request->string('alt_text')->toString() ?: null,
        ]);

        return (new MediaResource($media))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Event $event, Media $media): JsonResponse
    {
        $this->authorizeOwnership($request, $event);

        if ($media->mediable_id !== $event->id || $media->mediable_type !== Event::class) {
            throw new AccessDeniedHttpException('Média non rattaché à cet événement.');
        }

        Storage::disk('s3')->delete($media->path);
        $media->delete();

        return response()->json(null, 204);
    }

    private function authorizeOwnership(Request $request, Event $event): void
    {
        $organizerId = $request->user()->organizers()->first()?->id ?? 0;
        if ($event->organizer_id !== $organizerId) {
            throw new AccessDeniedHttpException("L'événement ne vous appartient pas.");
        }
    }
}
