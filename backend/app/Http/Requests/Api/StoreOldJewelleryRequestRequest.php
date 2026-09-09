<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOldJewelleryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'video' => ['required', 'file', 'mimes:mp4,mov,quicktime', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'video.required' => 'A video is required.',
        ];
    }
}
