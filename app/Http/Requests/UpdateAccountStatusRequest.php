<?php
// filepath: d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management\app\Http\Requests\UpdateAccountStatusRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
        ];
    }
}