<?php

namespace App\Http\Requests\Database;

use App\Models\DatabaseConnection;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            'password' => ['nullable', 'string', 'max:255'],
            'ssl' => ['nullable', 'boolean'],
        ];
    }

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
