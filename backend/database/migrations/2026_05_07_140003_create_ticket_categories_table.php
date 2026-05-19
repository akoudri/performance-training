<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_session_id')->constrained()->cascadeOnDelete();
            // Ex : "Carré Or", "Catégorie 1", "Catégorie 2".
            $table->string('name', 120);
            $table->integer('price_cents');
            $table->integer('quota');
            // Compteur dénormalisé incrémenté à chaque vente.
            // En starter : sans verrou explicite (race possible).
            // En final  : SELECT ... FOR UPDATE SKIP LOCKED (cf. §5 / §9).
            $table->integer('sold')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
