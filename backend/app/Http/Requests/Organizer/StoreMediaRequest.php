<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizer;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOrganizer() ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,mp4'],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
