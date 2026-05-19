<?php

namespace App\Models;

use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'ticket_category_id',
    'event_session_id',
    'code',
    'holder_name',
    'status',
    'pdf_path',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public const STATUS_VALID = 'valid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_USED = 'used';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketCategory(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class);
    }

    public function eventSession(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }
}
