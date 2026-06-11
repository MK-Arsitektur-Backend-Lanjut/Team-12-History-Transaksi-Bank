<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'export' => ['sometimes', 'in:csv'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end_date must be a date after or equal to start_date.',
        ];
    }
}
