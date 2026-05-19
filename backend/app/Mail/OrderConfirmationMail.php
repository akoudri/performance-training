<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation de commande envoyée APRÈS paiement.
 *
 * **NOTE STARTER** : ce Mailable n'implémente **pas** ShouldQueue, donc
 * Mail::to(...)->send($mail) est synchrone et bloque le thread HTTP. C'est
 * un choix pédagogique explicite (Q3 utilisateur) : on veut sentir le
 * coût UX du tunnel synchrone.
 *
 * @perf-debt: envoi SMTP bloquant — résolu en J3 atelier
 *             "laravel-redis-queues" en ajoutant ShouldQueue + dispatch
 *             via Mail::to($u)->queue($mail).
 */
class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Confirmation de commande #{$this->order->id} — Resonance",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
            with: [
                'order' => $this->order,
                'tickets' => $this->order->tickets,
            ],
        );
    }
}
