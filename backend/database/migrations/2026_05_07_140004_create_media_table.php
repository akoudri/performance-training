<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            // Polymorphique : rattachable à Event ou Organizer.
            $table->string('mediable_type');
            $table->unsignedBigInteger('mediable_id');
            // Type : image | video.
            $table->string('type', 20);
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->smallInteger('position')->default(0);
            $table->string('alt_text', 500)->nullable();
            $table->timestamps();

            // @perf-debt: pas d'index sur (mediable_type, mediable_id, position).
            // Toutes les requêtes d'événements lisant la galerie sont en N+1
            // par défaut — ajouté en J3.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
