<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('register → 201 + token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Alice',
        'email' => 'alice@test.local',
        'password' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);
});

it('register → 422 si email déjà pris', function (): void {
    User::factory()->create(['email' => 'dup@test.local']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Bob',
        'email' => 'dup@test.local',
        'password' => 'password123',
    ])->assertStatus(422);
});

it('login → 200 + token avec credentials valides', function (): void {
    User::factory()->create(['email' => 'carol@test.local']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'carol@test.local',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
});

it('login → 422 avec mauvais mot de passe', function (): void {
    User::factory()->create(['email' => 'dan@test.local']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'dan@test.local',
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

it('me → 200 quand authentifié', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role']]);
});

it('me → 401 sans token', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('logout → 200 et invalide le token courant', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/auth/logout')->assertOk();
});
