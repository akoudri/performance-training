<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Génère le PDF d'un ticket et le stocke dans MinIO.
 *
 * @perf-fix: appelé depuis `App\Jobs\GenerateTicketPdfJob` (queue Redis,
 *           supervisor Horizon). Plus de blocage du thread HTTP par
 *           dompdf (200-500 ms / ticket).
 */
class TicketPdfService
{
    /**
     * @return string chemin MinIO du PDF généré (mis aussi à jour sur le ticket)
     */
    public function generate(Ticket $ticket): string
    {
        $pdf = Pdf::loadView('pdf.ticket', [
            'ticket' => $ticket,
            'order' => $ticket->order,
            // Lazy-loading des relations toléré ici : on tourne dans le
            // worker Horizon (hors thread HTTP), 3-4 SELECT ajoutés sont
            // négligeables vs le coût dompdf lui-même (~ 200 ms).
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
