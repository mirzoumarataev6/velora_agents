<?php

namespace App\Http\Requests\Bi;

use App\Models\BiConnection;
use App\Services\Database\DriverManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The BI connection form, for both "Test" and "Save".
 *
 * Deliberately a SEPARATE class from `Database\SaveConnectionRequest` rather
 * than a subclass of it, even though the credential fields are the same six.
 * That request authorizes against an AGENT (`can('create', [DatabaseConnection,
 * $agent])`); this one authorizes against the user. Inheriting would have meant
 * the BI form's permission check depended on a route parameter it does not
 * have, and the first person to "simplify" the duplication would reintroduce
 * exactly the coupling this table was created to remove.
 *
 * `password` is nullable on purpose and means "keep the stored one": the form
 * never receives the secret back, so an owner editing a host submits an empty
 * password field. Only a non-empty value replaces it.
 */
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
            // The owner's own label. Required here and absent from the agent
            // form, because an agent has exactly one connection and needed no
            // way to tell it from another.
            'name' => ['required', 'string', 'max:120'],
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
