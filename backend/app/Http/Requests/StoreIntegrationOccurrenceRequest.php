<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntegrationOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'externalId'  => ['required', 'string', 'max:255'],
            'type'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'reportedAt'  => ['required', 'date'],
        ];
    }
}
