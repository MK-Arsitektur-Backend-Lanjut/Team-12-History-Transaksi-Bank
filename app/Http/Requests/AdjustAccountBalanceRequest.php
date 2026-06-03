<?php
// filepath: d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management\app\Http\Requests\AdjustAccountBalanceRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustAccountBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['debit', 'credit'])],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}