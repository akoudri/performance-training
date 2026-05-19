<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('doors_open_at')->nullable();
            // Statut : scheduled | cancelled | sold_out.
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();

            // @perf-debt: pas d'index sur (event_id, starts_at) — ajouté en J3.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sessions');
    }
};
