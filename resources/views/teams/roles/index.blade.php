@extends('layouts.dashboard')

@section('title', __('settings.roles.heading'))

@section('content')
@php
    $isSystem = $selectedRole && in_array($selectedRole->slug, \App\Services\Permissions\PermissionCatalog::systemRoleSlugs(), true);
    $postedPermissions = old('permissions', $errors->getBag('roles')->any() ? [] : $selectedPermissions);
    $chosenPermissions = is_array($postedPermissions) ? $postedPermissions : [];
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    <div class="page-header">
        <div>
            <a href="{{ route('settings.index').'#team' }}" class="text-sm text-brand hover:underline" data-i18n="settings.roles.back">{{ __('settings.roles.back') }}</a>
            <h1 class="page-title mt-2" data-i18n="settings.roles.heading">{{ __('settings.roles.heading') }}</h1>
            <p class="page-sub" data-i18n="settings.roles.description">{{ __('settings.roles.description') }}</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] lg:items-start">
        <div class="card p-3">
            <a href="{{ route('teams.roles.index') }}"
                class="block rounded-xl px-3 py-2.5 text-sm font-semibold {{ $selectedRole === null ? 'bg-brand-circle text-brand-strong' : 'hover:bg-sunk dark:hover:bg-night-raised' }}"
                data-i18n="settings.roles.new">{{ __('settings.roles.new') }}</a>
            <div class="mt-2 border-t border-hairline pt-2 dark:border-white/10">
                @foreach ($roles as $roleOption)
                    <a href="{{ route('teams.roles.index', ['edit' => $roleOption->id]) }}"
                        class="flex items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-sm {{ $selectedRole?->id === $roleOption->id ? 'bg-brand-circle text-brand-strong' : 'hover:bg-sunk dark:hover:bg-night-raised' }}">
                        <span class="min-w-0 truncate font-medium">{{ \Illuminate\Support\Facades\Lang::has('agents.nav.teams.roles.'.$roleOption->slug) ? __('agents.nav.teams.roles.'.$roleOption->slug) : $roleOption->name }}</span>
                        @if ($roleOption->is_owner)
                            <span class="badge badge-neutral shrink-0" data-i18n="settings.roles.system">{{ __('settings.roles.system') }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>

        <div class="card p-5 sm:p-6">
            <h2 class="font-display text-lg font-semibold">
                {{ $selectedRole === null ? __('settings.roles.new') : (\Illuminate\Support\Facades\Lang::has('agents.nav.teams.roles.'.$selectedRole->slug) ? __('agents.nav.teams.roles.'.$selectedRole->slug) : $selectedRole->name) }}
            </h2>
            <p class="mt-2 rounded-xl bg-sunk px-4 py-3 text-sm text-muted dark:bg-night-raised dark:text-night-muted" data-i18n="settings.roles.not_enforced_yet">{{ __('settings.roles.not_enforced_yet') }}</p>

            @if ($selectedRole?->is_owner)
                <p class="mt-4 text-sm font-medium" data-i18n="settings.roles.owner_protected">{{ __('settings.roles.owner_protected') }}</p>
            @else
                <form id="team-role-form" method="POST" action="{{ $selectedRole ? route('teams.roles.update', $selectedRole) : route('teams.roles.store') }}">
                    @csrf
                    @if ($selectedRole)
                        @method('PATCH')
                    @endif
            @endif

            @if (! $selectedRole || ! $isSystem)
                <div class="mt-5">
                    <label for="team-role-name" class="block text-sm font-medium" data-i18n="settings.roles.name">{{ __('settings.roles.name') }}</label>
                    <input id="team-role-name" name="name" type="text" maxlength="255" required
                        value="{{ old('name', $selectedRole?->name) }}" placeholder="{{ __('settings.roles.name_placeholder') }}"
                        class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                    @error('name', 'roles')
                        <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <h3 class="mt-6 text-sm font-semibold" data-i18n="settings.roles.permissions_heading">{{ __('settings.roles.permissions_heading') }}</h3>
            @if ($errors->getBag('roles')->has('permissions') || $errors->getBag('roles')->has('permissions.*'))
                <p class="mt-2 text-sm text-danger dark:text-danger-light">{{ $errors->getBag('roles')->first('permissions') ?: $errors->getBag('roles')->first('permissions.*') }}</p>
            @endif
            <div class="mt-3 space-y-5">
                @foreach ($permissionGroups as $group => $permissions)
                    <fieldset>
                        <legend class="text-xs font-semibold uppercase tracking-wide text-muted dark:text-night-muted" data-i18n="settings.roles.groups.{{ $group }}">{{ __('settings.roles.groups.'.$group) }}</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($permissions as $permission)
                                <label class="flex items-start gap-2.5 rounded-lg px-2 py-1.5 text-sm hover:bg-sunk dark:hover:bg-night-raised">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission }}"
                                        class="mt-0.5 rounded border-hairline text-brand focus:ring-brand"
                                        @checked(in_array($permission, $chosenPermissions, true)) @disabled($selectedRole?->is_owner) />
                                    <span data-i18n="settings.roles.permissions.{{ $permission }}">{{ __('settings.roles.permissions.'.$permission) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>

            @if (! $selectedRole?->is_owner)
                    <button type="submit" class="btn btn-primary mt-6" data-i18n="settings.roles.{{ $selectedRole ? 'save_button' : 'create_button' }}">
                        {{ $selectedRole ? __('settings.roles.save_button') : __('settings.roles.create_button') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
