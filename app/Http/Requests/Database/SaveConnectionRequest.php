<?php

namespace App\Http\Requests\Database;

use App\Models\DatabaseConnection;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The connection form, for both "Test connection" and "Save".
 *
 * `password` is nullable on purpose: the tab never receives the stored secret
 * back, so an owner editing the host of a saved connection submits an empty
 * password field and means "keep the one you have". Only a non-empty value
 * replaces it.
 */
class SaveConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [DatabaseConnection::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        return [
            'engine' => ['required', 'string', Rule::in(app(DriverManager::class)->keys())],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            // Nullable = "unchanged". See the class docblock.
            'password' => ['nullable', 'string', 'max:255'],
            'ssl' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return [
            'engine' => $this->string('engine')->toString(),
            'host' => trim($this->string('host')->toString()),
            'port' => (int) $this->input('port'),
            'database' => trim($this->string('database')->toString()),
            'username' => $this->string('username')->toString(),
            'password' => (string) $this->input('password', ''),
            'ssl' => $this->boolean('ssl'),
        ];
    }
}
