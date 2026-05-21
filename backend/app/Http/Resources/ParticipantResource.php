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
 * @perf-fix: la chaîne `ticket → order → user` et `ticket → ticketCategory`
 *           est eager-loadée par le caller (`with(['order.user',
 *           'ticketCategory'])`). Sur 7 000 tickets, on passe de
 *           ~21 000 SELECT à 3.
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
            'email' => $this->order?->user?->email,
            'category' => $this->ticketCategory?->name,
            'status' => $this->status,
        ];
    }
}
