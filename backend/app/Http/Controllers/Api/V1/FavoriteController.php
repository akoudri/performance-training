<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Favoris (toggle simple via la pivot `favorites`).
 */
class FavoriteController extends Controller
{
    public function store(Request $request, Event $event): JsonResponse
    {
        // syncWithoutDetaching évite l'erreur en cas de double POST.
        $request->user()->favoriteEvents()->syncWithoutDetaching([$event->id]);

        return response()->json(['message' => 'Ajouté aux favoris.'], 201);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $request->user()->favoriteEvents()->detach($event->id);

        return response()->json(['message' => 'Retiré des favoris.']);
    }
}
