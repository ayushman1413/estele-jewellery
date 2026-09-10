<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VendorAcceptBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('old-jewellery.vendor.show', $this->route('token'));
    }
}
