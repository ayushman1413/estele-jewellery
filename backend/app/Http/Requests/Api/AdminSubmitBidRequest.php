<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AdminSubmitBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level permission middleware (Task 10) is the real gate
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
