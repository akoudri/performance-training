<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\Organizer\EventController as OrganizerEventController;
use App\Http\Controllers\Api\V1\Organizer\MediaController as OrganizerMediaController;
use App\Http\Controllers\Api\V1\Organizer\ParticipantsController as OrganizerParticipantsController;
use App\Http\Controllers\Api\V1\Organizer\StatsController as OrganizerStatsController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Resonance — V1
|--------------------------------------------------------------------------
| Le préfixe "api/v1" est appliqué globalement par bootstrap/app.php.
| Cf. resonance-spec.md §6.
*/

// ---- Public ---------------------------------------------------------------

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{slug}', [EventController::class, 'show']);
Route::get('/events/{slug}/sessions', [EventController::class, 'sessions']);

// ---- Auth -----------------------------------------------------------------

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // ---- Visiteur authentifié --------------------------------------------

    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::get('/me/tickets', [TicketController::class, 'index']);

    // Spec §6 : /favorites/{event_id} — binding par id (et non slug).
    Route::post('/favorites/{event:id}', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{event:id}', [FavoriteController::class, 'destroy']);

    // ---- Organisateur ----------------------------------------------------

    Route::middleware('organizer')->prefix('organizer')->group(function (): void {
        Route::get('/stats', [OrganizerStatsController::class, 'stats']);
        Route::get('/sales-chart', [OrganizerStatsController::class, 'salesChart']);

        Route::get('/events', [OrganizerEventController::class, 'index']);
        Route::post('/events', [OrganizerEventController::class, 'store']);
        // Route binding par id (et non slug) sur l'espace organisateur
        // (cf. resonance-spec.md §6 — paths /organizer/events/{id}).
        Route::get('/events/{event:id}', [OrganizerEventController::class, 'show']);
        Route::patch('/events/{event:id}', [OrganizerEventController::class, 'update']);
        Route::delete('/events/{event:id}', [OrganizerEventController::class, 'destroy']);

        Route::post('/events/{event:id}/media', [OrganizerMediaController::class, 'store']);
        Route::delete('/events/{event:id}/media/{media}', [OrganizerMediaController::class, 'destroy']);

        Route::get('/events/{event:id}/participants', [OrganizerParticipantsController::class, 'index']);
    });
});
