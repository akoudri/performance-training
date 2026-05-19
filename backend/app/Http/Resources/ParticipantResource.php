<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue compacte d'un ticket pour la table "Participants" organisateur.
 * Colonnes attendues (cf. resonance-spec.md §5 écran 6) : code court, nom,
 * email, catégorie, statut.
 *
 * @perf-debt: chaîne ticket → order → user → email + ticket → category
 *             non eager-loadée → 2 SELECT par ligne. Résolu en J3.
 */
class ParticipantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 8 premiers chars de l'UUID pour affichage compact.
            'code_short' => substr($this->code, 0, 8),
            'holder_name' => $this->holder_name,
            // @perf-debt: ticket->order->user — N+1 garantie.
            'email' => $this->order->user->email,
            // @perf-debt: ticket->ticketCategory.
            'category' => $this->ticketCategory->name,
            'status' => $this->status,
        ];
    }
}
