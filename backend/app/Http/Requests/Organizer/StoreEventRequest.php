<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizer;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOrganizer() ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['required', Rule::in(Event::CATEGORIES)],
            'city' => ['required', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'venue_name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED])],
        ];
    }
}
