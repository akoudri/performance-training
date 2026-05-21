<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\TicketPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Génère le PDF d'un ticket en background (Redis queue, supervisor Horizon).
 *
 * Coût bloqué côté HTTP par dompdf (200-500 ms) → déporté ici. Le client
 * checkout reçoit son 201 dès que la transaction Postgres est commit, le
 * PDF est généré ensuite. Si l'utilisateur arrive sur /account/tickets
 * avant la fin du job, `pdf_path` est null — le frontend affiche "PDF
 * en cours de génération" (pattern accepté par le contrat §9 spec).
 */
class GenerateTicketPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly Ticket $ticket) {}

    public function handle(TicketPdfService $pdf): void
    {
        $pdf->generate($this->ticket);
    }
}
