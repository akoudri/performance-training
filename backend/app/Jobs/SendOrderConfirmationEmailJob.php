<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi de la mail de confirmation en background (Redis queue, supervisor
 * Horizon).
 *
 * SMTP synchrone bloque le thread HTTP (~ 100-300 ms à Mailpit local, plus
 * en prod). Déporter ici libère l'API checkout qui répond < 300 ms.
 */
class SendOrderConfirmationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly Order $order,
        public readonly string $email,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new OrderConfirmationMail($this->order));
    }
}
