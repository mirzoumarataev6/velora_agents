<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация регистрации, включая обязательное согласие с условиями.
 */
class RegisterRequest extends FormRequest
{
    /**
     * Версия политик, которую фиксируем на аккаунте при регистрации.
     * Почему так: без версии нельзя отличить согласие со старым текстом от
     * нового при будущем пересмотре политик — нужно будет повторно запросить
     * согласие только у тех, кто согласился со старой версией.
     */
    public const TERMS_VERSION = '2026-10-19';

    public function authorize(): bool
    {
        // Маршрут уже под middleware('guest') — здесь не требуется own-resource проверка.
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
