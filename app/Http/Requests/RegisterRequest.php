<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public const TERMS_VERSION = '2026-10-19';

    public function authorize(): bool
    {

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
            'terms_accepted' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => __('auth.validation.email_unique'),
            'terms_accepted.accepted' => __('auth.validation.terms_required'),
        ];
    }
}
