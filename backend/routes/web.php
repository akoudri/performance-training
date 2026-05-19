<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Stub nécessaire au middleware `auth:sanctum` : par défaut Laravel tente de
// rediriger les requêtes non-authentifiées vers route('login'). Notre app étant
// API-only, on renvoie un 401 JSON directement.
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');
