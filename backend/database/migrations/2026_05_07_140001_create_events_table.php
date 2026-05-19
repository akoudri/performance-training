<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description');
            // Catégorie : concert | festival | theater | conference | exhibition.
            $table->string('category', 20);
            $table->string('city', 120);
            $table->char('country', 2)->default('FR');
            $table->string('venue_name');
            $table->string('cover_image_path', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            // Statut : draft | published | archived.
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            // @perf-debt: aucun index sur (status, published_at), ni sur city,
            // category ou search FTS — ajoutés en J3 atelier "postgres-indexes".
            // Cf. resonance-spec.md §9.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
