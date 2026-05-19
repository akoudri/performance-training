<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pas d'`id` auto-incrémenté : PK composite (user_id, event_id).
        // Cf. resonance-spec.md §4. Le modèle Eloquent associé devra définir
        // `$incrementing = false` et `$primaryKey = ['user_id','event_id']`.
        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
