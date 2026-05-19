<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('total_cents');
            // Statut : pending | paid | failed | cancelled.
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            // Référence de paiement (mock UUID en starter — Stripe en annexe).
            $table->string('payment_reference', 120)->nullable();
            $table->timestamps();

            // @perf-debt: pas d'index sur (user_id, paid_at) ni paid_at partiel.
            // Le dashboard organisateur fait du `whereStatus('paid')` quotidien
            // — ajouté en J3.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
