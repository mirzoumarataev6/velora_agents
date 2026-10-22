<?php

namespace App\Http\Requests\Bi;

use App\Models\BiConnection;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBiConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof BiConnection
            ? $this->user()->can('update', $connection)
            : $this->user()->can('create', BiConnection::class);
    }

    public function rules(): array
    {
        return [

            'name' => ['required', 'string', 'max:120'],
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
            'name' => trim($this->string('name')->toString()),
            'engine' => $this->string('engine')->toString(),
            'host' => trim($this->string('host')->toString()),
            'port' => (int) $this->input('port'),
            'database' => trim($this->string('database')->toString()),
            'username' => $this->string('username')->toString(),
            'password' => (string) $this->input('password', ''),
            'ssl' => $this->boolean('ssl'),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('bi.validation.connection_name_required'),
        ];
    }
}
