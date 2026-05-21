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
 * Le Mailable lui-même reste synchrone (pas de `ShouldQueue` ici), mais
 * c'est `App\Jobs\SendOrderConfirmationEmailJob` qui est dispatché depuis
 * `OrderController@store`. C'est le job qui implémente ShouldQueue, le
 * Mailable est juste son payload — pattern explicite pour qu'un lecteur
 * voie immédiatement, depuis le contrôleur, le déport asynchrone (vs un
 * `ShouldQueue` "magique" sur le Mailable).
 *
 * @perf-fix: déport via SendOrderConfirmationEmailJob (queue Redis,
 *           supervisor Horizon).
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
