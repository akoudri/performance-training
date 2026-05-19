<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Mock de paiement pour le starter.
 *
 * Simule la latence d'un PSP (800-1500ms) avant de marquer la commande
 * comme payée. Toujours succès en starter — pas de gestion des
 * échecs réseau / fraude / etc.
 *
 * En annexe (cf. docs/annexes/stripe-integration.md) : le remplacement
 * par Stripe via PaymentIntents.
 *
 * @perf-debt: latence simulée *bloquante* dans le thread HTTP (le tunnel
 *             d'achat attend 800-1500ms avant la suite). En final, l'UX
 *             reste à 800-1500ms (réaliste) mais la génération PDF/email
 *             passe en queue (ShouldQueue), libérant le thread sur ~200ms.
 *             Cf. resonance-spec.md §9.
 */
class PaymentMockService
{
    public const MIN_LATENCY_MS = 800;

    public const MAX_LATENCY_MS = 1500;

    /**
     * Traite la commande : latence simulée + transition pending → paid.
     */
    public function process(Order $order): Order
    {
        // Latence PSP (cf. spec §8 : usleep(rand(800, 1500) * 1000)).
        $latencyMs = mt_rand(self::MIN_LATENCY_MS, self::MAX_LATENCY_MS);
        usleep($latencyMs * 1000);

        $order->update([
            'status' => Order::STATUS_PAID,
            'paid_at' => now(),
            'payment_reference' => Str::uuid()->toString(),
        ]);

        return $order;
    }
}
