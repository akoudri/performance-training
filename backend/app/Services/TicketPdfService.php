<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Génère le PDF d'un ticket et le stocke dans MinIO.
 *
 * @perf-debt: dompdf est synchrone, lent (200-500 ms par PDF), bloquant le
 *             thread HTTP. Pour 4 tickets dans une commande, on ajoute
 *             ~1-2s au tunnel d'achat. En final, on dispatch un
 *             GenerateTicketPdfJob en queue Redis (cf. spec §9), libérant
 *             le thread immédiatement après checkout.
 */
class TicketPdfService
{
    /**
     * @return string chemin MinIO du PDF généré (mis aussi à jour sur le ticket)
     */
    public function generate(Ticket $ticket): string
    {
        // @perf-debt: vue Blade compilée à chaque appel (pas de view:cache
        // en starter — cf. spec §8). Résolu par `php artisan optimize` en J3.
        $pdf = Pdf::loadView('pdf.ticket', [
            'ticket' => $ticket,
            'order' => $ticket->order,
            // @perf-debt: chargement à la demande des relations → 3 SELECT.
            'category' => $ticket->ticketCategory,
            'session' => $ticket->eventSession,
            'event' => $ticket->eventSession->event,
        ])->setPaper('a4', 'portrait');

        $path = sprintf('tickets/%d/ticket-%s.pdf', $ticket->order_id, $ticket->code);

        Storage::disk('s3')->put($path, $pdf->output(), [
            'ContentType' => 'application/pdf',
        ]);

        $ticket->update(['pdf_path' => $path]);

        return $path;
    }
}
