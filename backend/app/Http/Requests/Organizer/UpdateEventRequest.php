<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizer;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOrganizer() ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'category' => ['sometimes', Rule::in(Event::CATEGORIES)],
            'city' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'size:2'],
            'venue_name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_ARCHIVED])],
        ];
    }
}
