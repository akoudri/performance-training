<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'event_session_id' => ['required', 'integer', 'exists:event_sessions,id'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.ticket_category_id' => ['required', 'integer', 'exists:ticket_categories,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'items.*.holder_name' => ['required', 'string', 'max:255'],
        ];
    }
}
