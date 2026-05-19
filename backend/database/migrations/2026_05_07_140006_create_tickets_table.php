<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_category_id')->constrained()->cascadeOnDelete();
            // Dénormalisé pour les requêtes "participants par session" (§4).
            $table->foreignId('event_session_id')->constrained()->cascadeOnDelete();
            // UUID — sert au QR code.
            $table->uuid('code')->unique();
            $table->string('holder_name');
            // Statut : valid | cancelled | used.
            $table->string('status', 20)->default('valid');
            $table->string('pdf_path', 500)->nullable();
            $table->timestamps();

            // @perf-debt: pas d'index sur (event_session_id, created_at desc).
            // L'écran "Liste participants" lit jusqu'à 5000 lignes par event
            // sans tri optimisé — ajouté en J3.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
